<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Analyzer\Narrowing\AllOf;
use AutoDoc\Analyzer\Narrowing\AnyOf;
use AutoDoc\Analyzer\Narrowing\IsFalsey;
use AutoDoc\Analyzer\Narrowing\IsInstanceOf;
use AutoDoc\Analyzer\Narrowing\IsTruthy;
use AutoDoc\Analyzer\Narrowing\IsType;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\Narrowing\NotInstanceOf;
use AutoDoc\Analyzer\Narrowing\NotNull;
use AutoDoc\Analyzer\Narrowing\NotType;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
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
    ): void {

        $narrowings = self::extractNarrowings($conditionNode, $scope, negated: false);

        foreach ($narrowings as [$target, $narrowing]) {
            /** @var int */
            $filePos = $conditionNode->getAttribute('startFilePos');

            self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Emit narrowing events for the negation of a condition (e.g. the false
     * expression of a ternary, where the condition did not hold).
     */
    public static function emitNegatedNarrowingEvents(
        Node $conditionNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $narrowings = self::extractNarrowings($conditionNode, $scope, negated: true);

        foreach ($narrowings as [$target, $narrowing]) {
            /** @var int */
            $filePos = $conditionNode->getAttribute('startFilePos');

            self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Narrow a `switch` subject variable within a case body (e.g.
     * `switch ($x) { case 'a': ... }` narrows $x to 'a'). For fall-through cases
     * (`case 'a': case 'b': ...`) the subject is narrowed to the union of the case
     * values that reach the body. Narrowing is skipped if the group contains a
     * `default` or a non-literal value, since the body is then reachable for other
     * subject values too.
     */
    public static function emitCaseNarrowingEvents(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $caseNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $varName = self::getVariableName($switchNode->cond);

        // Only the case carrying the body narrows; empty fall-through cases are
        // folded into it below.
        if ($varName === null || $caseNode->stmts === []) {
            return;
        }

        // Collect the run of cases that fall through into this body: the body case
        // plus any immediately-preceding empty cases.
        $groupCases = [];

        foreach ($switchNode->cases as $case) {
            if ($case->stmts !== [] && $case !== $caseNode) {
                $groupCases = [];

                continue;
            }

            $groupCases[] = $case;

            if ($case === $caseNode) {
                break;
            }
        }

        $narrowings = [];

        foreach ($groupCases as $case) {
            // A `default` or non-literal value in the group means the body is also
            // reachable for other subject values, so it can't be narrowed.
            if ($case->cond === null || ! self::isLiteralScalarNode($case->cond)) {
                return;
            }

            $narrowings[] = new IsType($scope->resolveType($case->cond));
        }

        if ($narrowings === []) {
            return;
        }

        /** @var int */
        $filePos = $caseNode->getAttribute('startFilePos');

        $narrowing = count($narrowings) === 1 ? $narrowings[0] : new AnyOf($narrowings);

        $scope->eventLog->narrow($varName, $narrowing, $condition, $filePos);
    }

    /**
     * Emit negations of earlier switch case values when entering a later case.
     * A previous case value can only be removed if that case cannot fall through
     * into the current case.
     */
    public static function emitPreviousCaseNarrowingEvents(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $currentCase,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $subjectTarget = NarrowingTarget::fromNode($switchNode->cond);

        if ($subjectTarget === null) {
            return;
        }

        /** @var list<array{NarrowingTarget, Narrowing}> $narrowings */
        $narrowings = [];

        foreach ($switchNode->cases as $case) {
            if ($case === $currentCase) {
                break;
            }

            if ($case->cond === null || ! self::isLiteralScalarNode($case->cond)) {
                continue;
            }

            if (self::caseCanFallThroughToCase($switchNode, $case, $currentCase, $scope)) {
                continue;
            }

            $narrowings[] = [$subjectTarget, new NotType($scope->resolveType($case->cond))];
        }

        /** @var int */
        $filePos = $currentCase->getAttribute('startFilePos');

        foreach ($narrowings as [$target, $narrowing]) {
            self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Narrow a `match` subject variable to a match arm's condition value(s) within
     * that arm body (e.g. `match ($x) { 'a', 'b' => ... }` narrows $x to 'a'|'b').
     * Skipped for the `default` arm and arms with non-literal conditions.
     *
     * For `match (true)` the subject is not a variable; each arm condition is a
     * boolean expression, so the arm body is narrowed from that condition just like
     * an if-branch (handled only for single-condition arms — comma-separated arm
     * conditions are an OR, which can't narrow definitively).
     */
    public static function emitMatchArmNarrowingEvents(
        Node $subjectNode,
        Node\MatchArm $arm,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        if ($arm->conds === null) {
            return;
        }

        if (self::isTrueConst($subjectNode)) {
            if (count($arm->conds) !== 1) {
                return;
            }

            $narrowings = self::extractNarrowings($arm->conds[0], $scope, negated: false);

            /** @var int */
            $filePos = $arm->getAttribute('startFilePos');

            foreach ($narrowings as [$target, $narrowing]) {
                self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
            }

            return;
        }

        $varName = self::getVariableName($subjectNode);

        if ($varName === null) {
            return;
        }

        $narrowings = [];

        foreach ($arm->conds as $cond) {
            if (! self::isLiteralScalarNode($cond)) {
                return;
            }

            $narrowings[] = new IsType($scope->resolveType($cond));
        }

        if ($narrowings === []) {
            return;
        }

        /** @var int */
        $filePos = $arm->getAttribute('startFilePos');

        $narrowing = count($narrowings) === 1 ? $narrowings[0] : new AnyOf($narrowings);

        $scope->eventLog->narrow($varName, $narrowing, $condition, $filePos);
    }

    /**
     * Emit negations of earlier match arm conditions when entering a later arm.
     * Reaching the later arm means every previous arm condition was false; for
     * comma-separated arm conditions, every condition in that previous arm was
     * false.
     */
    public static function emitPreviousMatchArmNarrowingEvents(
        Node\Expr\Match_ $matchNode,
        Node\MatchArm $currentArm,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        /** @var list<array{NarrowingTarget, Narrowing}> $narrowings */
        $narrowings = [];
        $subjectTarget = NarrowingTarget::fromNode($matchNode->cond);
        $isTrueMatch = self::isTrueConst($matchNode->cond);

        if (! $isTrueMatch && $subjectTarget === null) {
            return;
        }

        foreach ($matchNode->arms as $arm) {
            if ($arm === $currentArm) {
                break;
            }

            if ($arm->conds === null) {
                return;
            }

            foreach ($arm->conds as $cond) {
                if ($isTrueMatch) {
                    $narrowings = array_merge($narrowings, self::extractNarrowings($cond, $scope, negated: true));

                } else if (self::isLiteralScalarNode($cond)) {
                    $narrowings[] = [$subjectTarget, new NotType($scope->resolveType($cond))];
                }
            }
        }

        /** @var int */
        $filePos = $currentArm->getAttribute('startFilePos');

        foreach ($narrowings as [$target, $narrowing]) {
            self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Emit negations of earlier if/elseif conditions when entering an elseif.
     */
    public static function emitPreviousElseIfNarrowingEvents(
        Node\Stmt\If_ $ifNode,
        Node\Stmt\ElseIf_ $currentElseif,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $narrowings = self::extractNarrowings($ifNode->cond, $scope, negated: true);

        foreach ($ifNode->elseifs as $elseif) {
            if ($elseif === $currentElseif) {
                break;
            }

            $narrowings = array_merge($narrowings, self::extractNarrowings($elseif->cond, $scope, negated: true));
        }

        /** @var int */
        $filePos = $currentElseif->getAttribute('startFilePos');

        foreach ($narrowings as [$target, $narrowing]) {
            self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Emit the negation of an if/elseif chain's conditions (true in the `else`
     * branch, or — for a chain whose every branch breaks out — in the code after
     * the chain). `$filePos` defaults to the start of the `if` (for the `else`
     * branch); pass the chain's end position to narrow the following code.
     */
    public static function emitElseNarrowingEvents(
        Node\Stmt\If_ $ifNode,
        Scope $scope,
        PhpCondition $condition,
        ?int $filePos = null,
    ): void {

        if ($filePos === null) {
            /** @var int */
            $filePos = $ifNode->getAttribute('startFilePos');
        }

        $narrowings = self::extractNarrowings($ifNode->cond, $scope, negated: true);

        foreach ($ifNode->elseifs as $elseif) {
            $narrowings = array_merge($narrowings, self::extractNarrowings($elseif->cond, $scope, negated: true));
        }

        foreach ($narrowings as [$target, $narrowing]) {
            self::emitTarget($scope, $target, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Emit a single narrowing for a target: a plain variable or attribute path.
     */
    private static function emitTarget(
        Scope $scope,
        NarrowingTarget $target,
        Narrowing $narrowing,
        PhpCondition $condition,
        int $filePos,
    ): void {

        $path = $target->attributePath();

        if ($path !== null) {
            $scope->eventLog->narrowAttributePath($target->baseVar, $path, $narrowing, $condition, $filePos);

        } else {
            $scope->eventLog->narrow($target->baseVar, $narrowing, $condition, $filePos);
        }
    }

    /**
     * Extract narrowings from a condition expression.
     *
     * @return list<array{NarrowingTarget, Narrowing}>
     */
    private static function extractNarrowings(Node $node, Scope $scope, bool $negated): array
    {
        // instanceof check: if ($x instanceof Foo)
        if ($node instanceof Node\Expr\Instanceof_) {
            $target = NarrowingTarget::fromNode($node->expr);

            if ($target !== null && $node->class instanceof Node\Name) {
                $className = $scope->getResolvedClassName($node->class);

                if ($className) {
                    if (! $negated) {
                        return [[$target, new IsInstanceOf($className)]];
                    }

                    return [[$target, new NotInstanceOf($className)]];
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
                $target = NarrowingTarget::fromNode($varSide);

                if ($target !== null) {
                    if ($isNotIdentical) {
                        return [[$target, new NotNull]];

                    } else {
                        return [[$target, new IsType(new NullType)]];
                    }
                }
            }

            // Identity comparison to a literal: `$x === 'json'` narrows to the
            // literal, while `$x !== 'json'` removes it from finite unions.
            $leftTarget = NarrowingTarget::fromNode($node->left);
            $rightTarget = NarrowingTarget::fromNode($node->right);

            if ($leftTarget !== null && self::isLiteralScalarNode($node->right)) {
                $literalType = $scope->resolveType($node->right);

                return [[$leftTarget, $isNotIdentical ? new NotType($literalType) : new IsType($literalType)]];
            }

            if ($rightTarget !== null && self::isLiteralScalarNode($node->left)) {
                $literalType = $scope->resolveType($node->left);

                return [[$rightTarget, $isNotIdentical ? new NotType($literalType) : new IsType($literalType)]];
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

            if (self::isNull($node->left)) {
                $varSide = $node->right;

            } else if (self::isNull($node->right)) {
                $varSide = $node->left;
            }

            if ($varSide !== null) {
                $target = NarrowingTarget::fromNode($varSide);

                if ($target !== null) {
                    $narrowing = $isNotEqual
                        ? new NotType(new NullType, strict: false)
                        : new IsType(new NullType, strict: false);

                    return [[$target, $narrowing]];
                }

                return [];
            }

            $leftTarget = NarrowingTarget::fromNode($node->left);
            $rightTarget = NarrowingTarget::fromNode($node->right);

            if ($leftTarget !== null && self::isLiteralScalarNode($node->right)) {
                $literalType = $scope->resolveType($node->right);

                return [[$leftTarget, $isNotEqual ? new NotType($literalType, strict: false) : new IsType($literalType, strict: false)]];
            }

            if ($rightTarget !== null && self::isLiteralScalarNode($node->left)) {
                $literalType = $scope->resolveType($node->left);

                return [[$rightTarget, $isNotEqual ? new NotType($literalType, strict: false) : new IsType($literalType, strict: false)]];
            }

            return [];
        }

        // Boolean not: if (!$x) — negates the inner expression
        if ($node instanceof Node\Expr\BooleanNot) {
            return self::extractNarrowings($node->expr, $scope, ! $negated);
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $narrowings = $scope->getNarrowingsFromFuncCallExtensions(new FuncCallContext($node, $scope), $negated);

            if ($narrowings !== []) {
                return $narrowings;
            }
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $narrowings = $scope->getNarrowingsFromMethodCallExtensions(new MethodCallContext($node, $scope), $negated);

            if ($narrowings !== []) {
                return $narrowings;
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $narrowings = $scope->getNarrowingsFromStaticCallExtensions(new StaticCallContext($node, $scope), $negated);

            if ($narrowings !== []) {
                return $narrowings;
            }
        }

        // Truthy condition: if ($x) or if ($obj->prop). Finite scalar/bool unions
        // can be filtered by PHP's boolean-cast rules; open types stay broad.
        $truthyTarget = NarrowingTarget::fromNode($node);

        if ($truthyTarget !== null) {
            return [[$truthyTarget, $negated ? new IsFalsey : new IsTruthy]];
        }

        // is_array, is_string, is_int, etc.
        if ($node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && count($node->args) === 1
            && $node->args[0] instanceof Node\Arg
        ) {
            $funcName = $node->name->name;
            $target = NarrowingTarget::fromNode($node->args[0]->value);

            if ($target !== null) {
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
                        return [[$target, new IsType($type)]];
                    }

                    // Negated is_null is equivalent to !== null.
                    if ($type instanceof NullType) {
                        return [[$target, new NotNull]];
                    }

                    // is_numeric matches several types (int|float|numeric-string), so
                    // its negation can't be expressed as removing a single type class.
                    if (! ($type instanceof NumberType)) {
                        return [[$target, new NotType($type)]];
                    }
                }
            }

            return [];
        }

        // empty($x) — the false branch (`!empty($x)`, or the code after an
        // `if (empty($x)) { return; }` guard) guarantees non-null. The true branch
        // covers every falsy value (null|false|0|''|'0'|[]), which can't be reduced
        // to a single removable type, so it isn't narrowed.
        if ($node instanceof Node\Expr\Empty_) {
            $target = NarrowingTarget::fromNode($node->expr);

            if ($target !== null && $negated) {
                return [[$target, new NotNull]];
            }

            return [];
        }

        // isset($x) — narrows to non-null
        if ($node instanceof Node\Expr\Isset_) {
            $narrowings = [];

            foreach ($node->vars as $var) {
                $target = NarrowingTarget::fromNode($var);

                if ($target !== null) {
                    if (! $negated) {
                        $narrowings[] = [$target, new NotNull];

                    } else {
                        $narrowings[] = [$target, new IsType(new NullType)];
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
            $leftNarrowings = self::extractNarrowings($node->left, $scope, $negated);
            $rightNarrowings = self::extractNarrowings($node->right, $scope, $negated);

            $isAndContext = ($node instanceof Node\Expr\BinaryOp\BooleanAnd) !== $negated;

            if ($isAndContext) {
                return array_merge($leftNarrowings, $rightNarrowings);
            }

            return self::combineOrNarrowings($leftNarrowings, $rightNarrowings);
        }

        return [];
    }

    /**
     * Combine the narrowings from the two operands of a logical OR. A target is
     * narrowed only if both operands narrow it, and its narrowed type becomes the
     * union of the per-operand narrowings.
     *
     * @param list<array{NarrowingTarget, Narrowing}> $left
     * @param list<array{NarrowingTarget, Narrowing}> $right
     * @return list<array{NarrowingTarget, Narrowing}>
     */
    private static function combineOrNarrowings(array $left, array $right): array
    {
        /** @var array<string, array{NarrowingTarget, list<Narrowing>}> $leftByVar */
        $leftByVar = [];

        foreach ($left as [$target, $narrowing]) {
            $id = $target->id();
            $leftByVar[$id] ??= [$target, []];
            $leftByVar[$id][1][] = $narrowing;
        }

        /** @var array<string, array{NarrowingTarget, list<Narrowing>}> $rightByVar */
        $rightByVar = [];

        foreach ($right as [$target, $narrowing]) {
            $id = $target->id();
            $rightByVar[$id] ??= [$target, []];
            $rightByVar[$id][1][] = $narrowing;
        }

        $combined = [];

        foreach ($leftByVar as $id => [$target, $leftTypes]) {
            if (! isset($rightByVar[$id])) {
                continue;
            }

            $rightTypes = $rightByVar[$id][1];
            $leftNarrowing = count($leftTypes) === 1 ? $leftTypes[0] : new AllOf($leftTypes);
            $rightNarrowing = count($rightTypes) === 1 ? $rightTypes[0] : new AllOf($rightTypes);

            $combined[] = [$target, new AnyOf([$leftNarrowing, $rightNarrowing])];
        }

        return $combined;
    }

    private static function getVariableName(Node $node): ?string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $node->name;
        }

        return null;
    }

    private static function caseCanFallThroughToCase(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $fromCase,
        Node\Stmt\Case_ $toCase,
        Scope $scope,
    ): bool {

        $started = false;

        foreach ($switchNode->cases as $case) {
            if ($case === $fromCase) {
                $started = true;
            }

            if (! $started) {
                continue;
            }

            if ($case === $toCase) {
                return true;
            }

            if (self::casePreventsFallthrough($case, $scope)) {
                return false;
            }
        }

        return true;
    }

    private static function casePreventsFallthrough(Node\Stmt\Case_ $case, Scope $scope): bool
    {
        $lastStatementKey = array_key_last($case->stmts);

        if ($lastStatementKey === null) {
            return false;
        }

        $lastStatement = $case->stmts[$lastStatementKey];

        return $lastStatement instanceof Node\Stmt\Return_
            || $lastStatement instanceof Node\Stmt\Break_
            || $lastStatement instanceof Node\Stmt\Continue_
            || ($lastStatement instanceof Node\Stmt\Expression
                && ($lastStatement->expr instanceof Node\Expr\Exit_
                    || $lastStatement->expr instanceof Node\Expr\Throw_))
            || new BranchBreakout($scope)->statementBreaksOut($lastStatement);
    }

    private static function isNull(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && $node->name->toString() === 'null';
    }

    private static function isTrueConst(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && strtolower($node->name->toString()) === 'true';
    }

    private static function isLiteralScalarNode(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
            || ($node instanceof Node\Expr\ConstFetch && in_array($node->name->toString(), ['true', 'false'], true));
    }
}
