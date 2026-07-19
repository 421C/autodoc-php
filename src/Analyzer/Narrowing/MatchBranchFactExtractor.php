<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use PhpParser\Node;


final readonly class MatchBranchFactExtractor
{
    public function __construct(
        private ConditionFactExtractor $conditionFacts = new ConditionFactExtractor,
    ) {}

    /**
     * @return list<NarrowingFact>
     */
    public function factsForArm(
        Node\Expr\Match_ $matchNode,
        Node\MatchArm $arm,
        Scope $scope,
    ): array {

        if ($arm->conds === null) {
            return [];
        }

        if ($this->hasBooleanConditionSubject($matchNode)) {
            return $this->factsForBooleanConditionArm($arm, $scope);
        }

        $subjectTarget = Target::fromNode($matchNode->cond);

        if ($subjectTarget === null) {
            return [];
        }

        $narrowing = $this->narrowingForConditions($arm->conds, $scope);

        return $narrowing === null ? [] : [new NarrowingFact($subjectTarget, $narrowing)];
    }

    /**
     * @return list<NarrowingFact>
     */
    public function exclusionsBeforeArm(
        Node\Expr\Match_ $matchNode,
        Node\MatchArm $currentArm,
        Scope $scope,
    ): array {

        $hasBooleanConditionSubject = $this->hasBooleanConditionSubject($matchNode);
        $subjectTarget = Target::fromNode($matchNode->cond);

        if (! $hasBooleanConditionSubject && $subjectTarget === null) {
            return [];
        }

        $facts = [];

        foreach ($matchNode->arms as $arm) {
            if ($arm === $currentArm) {
                break;
            }

            if ($arm->conds === null) {
                return [];
            }

            foreach ($arm->conds as $conditionNode) {
                if ($hasBooleanConditionSubject) {
                    $facts = array_merge(
                        $facts,
                        $this->conditionFacts->extract($conditionNode, $scope, negated: true),
                    );

                } else if ($this->isLiteralScalar($conditionNode)) {
                    $facts[] = new NarrowingFact(
                        target: $subjectTarget,
                        narrowing: new NotType($scope->resolveType($conditionNode)),
                    );
                }
            }
        }

        return $facts;
    }

    /**
     * @return list<NarrowingFact>
     */
    private function factsForBooleanConditionArm(Node\MatchArm $arm, Scope $scope): array
    {
        if ($arm->conds === null || count($arm->conds) !== 1) {
            return [];
        }

        return $this->conditionFacts->extract($arm->conds[0], $scope);
    }

    /**
     * @param list<Node\Expr> $conditions
     */
    private function narrowingForConditions(array $conditions, Scope $scope): ?Narrowing
    {
        $conditionNarrowings = [];

        foreach ($conditions as $conditionNode) {
            if (! $this->isLiteralScalar($conditionNode)) {
                return null;
            }

            $conditionNarrowings[] = new IsType($scope->resolveType($conditionNode));
        }

        if ($conditionNarrowings === []) {
            return null;
        }

        return count($conditionNarrowings) === 1
            ? $conditionNarrowings[0]
            : new AnyOf($conditionNarrowings);
    }

    private function hasBooleanConditionSubject(Node\Expr\Match_ $matchNode): bool
    {
        return $matchNode->cond instanceof Node\Expr\ConstFetch
            && strtolower($matchNode->cond->name->toString()) === 'true';
    }

    private function isLiteralScalar(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
            || ($node instanceof Node\Expr\ConstFetch && in_array($node->name->toString(), ['true', 'false'], true));
    }
}
