<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use PhpParser\Node;

class TypeNarrower
{
    /**
     * Emit narrowing events when entering an if/elseif branch.
     */
    public static function emitNarrowingEvents(
        Node $conditionNode,
        Scope $scope,
        PhpCondition $condition,
        int $branchIndex,
    ): void {

        $narrowings = self::extractNarrowings($conditionNode, $scope, negated: false);

        foreach ($narrowings as [$varName, $narrowedType]) {
            /** @var int */
            $filePos = $conditionNode->getAttribute('startFilePos');

            $scope->eventLog->narrow($varName, $narrowedType, $condition, $filePos);
        }
    }

    /**
     * Emit narrowing events for the else branch.
     * This is the negation of the if condition (and all elseif conditions).
     */
    public static function emitElseNarrowingEvents(
        Node\Stmt\If_ $ifNode,
        Scope $scope,
        PhpCondition $condition,
        int $branchIndex,
    ): void {

        $narrowings = self::extractNarrowings($ifNode->cond, $scope, negated: true);

        foreach ($ifNode->elseifs as $elseif) {
            $narrowings = array_merge($narrowings, self::extractNarrowings($elseif->cond, $scope, negated: true));
        }

        foreach ($narrowings as [$varName, $narrowedType]) {
            /** @var int */
            $filePos = $ifNode->getAttribute('startFilePos');

            $scope->eventLog->narrow($varName, $narrowedType, $condition, $filePos);
        }
    }

    /**
     * Extract variable narrowings from a condition expression.
     *
     * @return list<array{string, Type}>
     */
    private static function extractNarrowings(Node $node, Scope $scope, bool $negated): array
    {
        // instanceof check: if ($x instanceof Foo)
        if ($node instanceof Node\Expr\Instanceof_) {
            $varName = self::getVariableName($node->expr);

            if ($varName !== null && $node->class instanceof Node\Name) {
                $className = $scope->getResolvedClassName($node->class);

                if ($className) {
                    if (! $negated) {
                        return [[$varName, new ObjectType(className: $className)]];

                    } else {
                        return [[$varName, new NullType]];
                    }
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

            if (self::isNull($node->left)) {
                $nullSide = $node->left;
                $varSide = $node->right;

            } else if (self::isNull($node->right)) {
                $nullSide = $node->right;
                $varSide = $node->left;
            }

            if ($nullSide !== null && $varSide !== null) {
                $varName = self::getVariableName($varSide);

                if ($varName !== null) {
                    if ($isNotIdentical) {
                        // In the "not null" branch, we can't express "remove null" as a Type
                        // — we'll handle it via a special marker
                        return [[$varName, new NonNullNarrowedType]];

                    } else {
                        return [[$varName, new NullType]];
                    }
                }
            }

            return [];
        }

        // Boolean not: if (!$x) — negates the inner expression
        if ($node instanceof Node\Expr\BooleanNot) {
            return self::extractNarrowings($node->expr, $scope, ! $negated);
        }

        // is_array, is_string, is_int, etc.
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && count($node->args) === 1
            && $node->args[0] instanceof Node\Arg
        ) {
            $funcName = $node->name->name;
            $varName = self::getVariableName($node->args[0]->value);

            if ($varName !== null) {
                $type = match ($funcName) {
                    'is_array' => new ArrayType,
                    'is_string' => new StringType,
                    'is_int', 'is_integer', 'is_long' => new IntegerType,
                    'is_float', 'is_double' => new FloatType,
                    'is_numeric' => new NumberType,
                    'is_bool' => new BoolType,
                    'is_null' => new NullType,
                    'is_object' => new ObjectType,
                    default => null,
                };

                if ($type !== null) {
                    if (! $negated) {
                        return [[$varName, $type]];
                    }

                    // Negated is_null is equivalent to !== null
                    if ($funcName === 'is_null') {
                        return [[$varName, new NonNullNarrowedType]];
                    }
                }
            }

            return [];
        }

        // isset($x) — narrows to non-null
        if ($node instanceof Node\Expr\Isset_) {
            $narrowings = [];

            foreach ($node->vars as $var) {
                $varName = self::getVariableName($var);

                if ($varName !== null) {
                    if (! $negated) {
                        $narrowings[] = [$varName, new NonNullNarrowedType];

                    } else {
                        $narrowings[] = [$varName, new NullType];
                    }
                }
            }

            return $narrowings;
        }

        // && combines narrowings (both must be true)
        if (! $negated && $node instanceof Node\Expr\BinaryOp\BooleanAnd) {
            return array_merge(
                self::extractNarrowings($node->left, $scope, false),
                self::extractNarrowings($node->right, $scope, false),
            );
        }

        // || in negated context: both sides are negated
        if ($negated && $node instanceof Node\Expr\BinaryOp\BooleanOr) {
            return array_merge(
                self::extractNarrowings($node->left, $scope, true),
                self::extractNarrowings($node->right, $scope, true),
            );
        }

        return [];
    }

    private static function getVariableName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $node->name;
        }

        return null;
    }

    private static function isNull(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && $node->name->toString() === 'null';
    }
}
