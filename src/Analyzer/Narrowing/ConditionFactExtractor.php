<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\NullType;
use AutoDoc\Extensions\FuncCallContext;
use AutoDoc\Extensions\MethodCallContext;
use AutoDoc\Extensions\StaticCallContext;
use PhpParser\Node;

final class ConditionFactExtractor
{
    /**
     * Extract narrowings from a condition expression.
     *
     * @return list<NarrowingFact>
     */
    public function extract(Node $node, Scope $scope, bool $negated = false): array
    {
        // instanceof check: if ($x instanceof Foo)
        if ($node instanceof Node\Expr\Instanceof_) {
            $target = Target::fromNode($node->expr);

            if ($target !== null && $node->class instanceof Node\Name) {
                $className = $scope->getResolvedClassName($node->class);

                if ($className) {
                    if (! $negated) {
                        return [new NarrowingFact($target, new IsInstanceOf($className))];
                    }

                    return [new NarrowingFact($target, new NotInstanceOf($className))];
                }
            }

            return [];
        }

        // Not-identical comparison: if ($x !== null) or if ($x === null)
        if ($node instanceof Node\Expr\BinaryOp\NotIdentical || $node instanceof Node\Expr\BinaryOp\Identical) {
            $isNotIdentical = $node instanceof Node\Expr\BinaryOp\NotIdentical;

            if ($negated) {
                $isNotIdentical = ! $isNotIdentical;
            }

            // $x !== null → narrow to non-null in true branch
            // $x === null → narrow to NullType in true branch
            $nullSide = null;
            $varSide = null;

            if ($this->isNull($node->left)) {
                $nullSide = $node->left;
                $varSide = $node->right;

            } else if ($this->isNull($node->right)) {
                $nullSide = $node->right;
                $varSide = $node->left;
            }

            if ($nullSide !== null && $varSide !== null) {
                $target = Target::fromNode($varSide);

                if ($target !== null) {
                    if ($isNotIdentical) {
                        return [new NarrowingFact($target, new NotNull)];

                    } else {
                        return [new NarrowingFact($target, new IsType(new NullType))];
                    }
                }
            }

            // Identity comparison to a literal: `$x === 'json'` narrows to the
            // literal, while `$x !== 'json'` removes it from finite unions.
            $leftTarget = Target::fromNode($node->left);
            $rightTarget = Target::fromNode($node->right);

            if ($leftTarget !== null && $this->isLiteralScalarNode($node->right)) {
                $literalType = $scope->resolveType($node->right);

                return [new NarrowingFact(
                    target: $leftTarget,
                    narrowing: $isNotIdentical ? new NotType($literalType) : new IsType($literalType),
                )];
            }

            if ($rightTarget !== null && $this->isLiteralScalarNode($node->left)) {
                $literalType = $scope->resolveType($node->left);

                return [new NarrowingFact(
                    target: $rightTarget,
                    narrowing: $isNotIdentical ? new NotType($literalType) : new IsType($literalType),
                )];
            }

            return [];
        }

        // Loose comparison: if ($x == 'json') or if ($x != 'json'). Narrows by
        // PHP's loose comparison rules (`IsType`/`NotType` in loose mode) rather
        // than the strict identity handling above.
        if ($node instanceof Node\Expr\BinaryOp\Equal || $node instanceof Node\Expr\BinaryOp\NotEqual) {
            $isNotEqual = $node instanceof Node\Expr\BinaryOp\NotEqual;

            if ($negated) {
                $isNotEqual = ! $isNotEqual;
            }

            // Loose null comparison: narrow finite scalar/bool unions by the same
            // loose rules as any other value comparison. This removes `null`, `0`,
            // `''`, and `false` from the `$x != null` side when those exact values
            // are known, while leaving open scalar types unchanged.
            $varSide = null;

            if ($this->isNull($node->left)) {
                $varSide = $node->right;

            } else if ($this->isNull($node->right)) {
                $varSide = $node->left;
            }

            if ($varSide !== null) {
                $target = Target::fromNode($varSide);

                if ($target !== null) {
                    $narrowing = $isNotEqual
                        ? new NotType(new NullType, strict: false)
                        : new IsType(new NullType, strict: false);

                    return [new NarrowingFact($target, $narrowing)];
                }

                return [];
            }

            $leftTarget = Target::fromNode($node->left);
            $rightTarget = Target::fromNode($node->right);

            if ($leftTarget !== null && $this->isLiteralScalarNode($node->right)) {
                $literalType = $scope->resolveType($node->right);

                return [new NarrowingFact(
                    target: $leftTarget,
                    narrowing: $isNotEqual ? new NotType($literalType, strict: false) : new IsType($literalType, strict: false),
                )];
            }

            if ($rightTarget !== null && $this->isLiteralScalarNode($node->left)) {
                $literalType = $scope->resolveType($node->left);

                return [new NarrowingFact(
                    target: $rightTarget,
                    narrowing: $isNotEqual ? new NotType($literalType, strict: false) : new IsType($literalType, strict: false),
                )];
            }

            return [];
        }

        // Boolean not: if (!$x) — negates the inner expression
        if ($node instanceof Node\Expr\BooleanNot) {
            return $this->extract($node->expr, $scope, ! $negated);
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $narrowings = $scope->extensions->getNarrowingsFromFuncCallExtensions(new FuncCallContext($node, $scope), $negated);

            if ($narrowings !== []) {
                return $narrowings;
            }
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $narrowings = $scope->extensions->getNarrowingsFromMethodCallExtensions(new MethodCallContext($node, $scope), $negated);

            if ($narrowings !== []) {
                return $narrowings;
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $narrowings = $scope->extensions->getNarrowingsFromStaticCallExtensions(new StaticCallContext($node, $scope), $negated);

            if ($narrowings !== []) {
                return $narrowings;
            }
        }

        // Truthy condition: if ($x) or if ($obj->prop). Finite scalar/bool unions
        // can be filtered by PHP's boolean-cast rules; open types stay broad.
        $truthyTarget = Target::fromNode($node);

        if ($truthyTarget !== null) {
            return [new NarrowingFact($truthyTarget, $negated ? new IsFalsey : new IsTruthy)];
        }

        // empty($x) — the false branch (`!empty($x)`, or the code after an
        // `if (empty($x)) { return; }` guard) guarantees truthy values. Literal
        // attribute paths also become present, since empty() returns true for
        // missing attributes. The true branch covers every falsy value and isn't
        // narrowed.
        if ($node instanceof Node\Expr\Empty_) {
            $target = Target::fromNode($node->expr);

            if ($target !== null && $negated) {
                $narrowing = $target->isAttribute()
                    ? new AllOf([new IsTruthy, new IsPresent])
                    : new IsTruthy;

                return [new NarrowingFact($target, $narrowing)];
            }

            return [];
        }

        // isset($x) — the true side guarantees the target is present and non-null,
        // so an attribute path it targets becomes required (presence narrowing).
        if ($node instanceof Node\Expr\Isset_) {
            $narrowings = [];

            foreach ($node->vars as $var) {
                $target = Target::fromNode($var);

                if ($target !== null) {
                    if (! $negated) {
                        $narrowings[] = new NarrowingFact($target, new AllOf([new NotNull, new IsPresent]));

                    } else {
                        $narrowings[] = new NarrowingFact($target, new IsType(new NullType));
                    }
                }
            }

            return $narrowings;
        }

        // Logical AND/OR: recurse into both operands with the same negation, then
        // combine. In an AND context (positive `&&` or negated `||`) every operand
        // holds, so all narrowings apply. In an OR context (positive `||` or
        // negated `&&`) only one operand is guaranteed, so a variable is narrowed
        // only if both operands narrow it — to the union of those narrowings.
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd
            || $node instanceof Node\Expr\BinaryOp\BooleanOr
        ) {
            $leftNarrowings = $this->extract($node->left, $scope, $negated);
            $rightNarrowings = $this->extract($node->right, $scope, $negated);

            $isAndContext = ($node instanceof Node\Expr\BinaryOp\BooleanAnd) !== $negated;

            if ($isAndContext) {
                return array_merge($leftNarrowings, $rightNarrowings);
            }

            return $this->combineOrNarrowings($leftNarrowings, $rightNarrowings);
        }

        return [];
    }

    /**
     * Combine the narrowings from the two operands of a logical OR. A target is
     * narrowed only if both operands narrow it, and its narrowed type becomes the
     * union of the per-operand narrowings.
     *
     * @param list<NarrowingFact> $left
     * @param list<NarrowingFact> $right
     * @return list<NarrowingFact>
     */
    private function combineOrNarrowings(array $left, array $right): array
    {
        /** @var array<string, array{Target, list<Narrowing>}> $leftByVar */
        $leftByVar = [];

        foreach ($left as $fact) {
            $id = $fact->target->id();
            $leftByVar[$id] ??= [$fact->target, []];
            $leftByVar[$id][1][] = $fact->narrowing;
        }

        /** @var array<string, array{Target, list<Narrowing>}> $rightByVar */
        $rightByVar = [];

        foreach ($right as $fact) {
            $id = $fact->target->id();
            $rightByVar[$id] ??= [$fact->target, []];
            $rightByVar[$id][1][] = $fact->narrowing;
        }

        $combined = [];

        foreach ($leftByVar as $id => [$target, $leftTypes]) {
            if (! isset($rightByVar[$id])) {
                continue;
            }

            $rightTypes = $rightByVar[$id][1];
            $leftNarrowing = count($leftTypes) === 1 ? $leftTypes[0] : new AllOf($leftTypes);
            $rightNarrowing = count($rightTypes) === 1 ? $rightTypes[0] : new AllOf($rightTypes);

            $combined[] = new NarrowingFact($target, new AnyOf([$leftNarrowing, $rightNarrowing]));
        }

        return $combined;
    }

    private function isNull(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && $node->name->toString() === 'null';
    }

    private function isLiteralScalarNode(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
            || ($node instanceof Node\Expr\ConstFetch && in_array($node->name->toString(), ['true', 'false'], true));
    }
}
