<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Flow\PhpCondition;
use AutoDoc\Analyzer\Scope;
use PhpParser\Node;


final readonly class BranchNarrowingEmitter
{
    public function __construct(
        private ConditionFactExtractor $conditionFacts = new ConditionFactExtractor,
        private SwitchBranchFactExtractor $switchFacts = new SwitchBranchFactExtractor,
        private MatchBranchFactExtractor $matchFacts = new MatchBranchFactExtractor,
    ) {}

    public function emitForCondition(
        Node $conditionNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->conditionFacts->extract($conditionNode, $scope),
            scope: $scope,
            condition: $condition,
            node: $conditionNode,
        );
    }

    public function emitForNegatedCondition(
        Node $conditionNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->conditionFacts->extract($conditionNode, $scope, negated: true),
            scope: $scope,
            condition: $condition,
            node: $conditionNode,
        );
    }

    public function emitForSwitchCase(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $caseNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->switchFacts->factsForCaseBody($switchNode, $caseNode, $scope),
            scope: $scope,
            condition: $condition,
            node: $caseNode,
        );
    }

    public function emitExclusionsBeforeSwitchCase(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $caseNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->switchFacts->exclusionsBeforeCase($switchNode, $caseNode, $scope),
            scope: $scope,
            condition: $condition,
            node: $caseNode,
        );
    }

    public function emitForMatchArm(
        Node\Expr\Match_ $matchNode,
        Node\MatchArm $arm,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->matchFacts->factsForArm($matchNode, $arm, $scope),
            scope: $scope,
            condition: $condition,
            node: $arm,
        );
    }

    public function emitExclusionsBeforeMatchArm(
        Node\Expr\Match_ $matchNode,
        Node\MatchArm $arm,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->matchFacts->exclusionsBeforeArm($matchNode, $arm, $scope),
            scope: $scope,
            condition: $condition,
            node: $arm,
        );
    }

    public function emitExclusionsBeforeElseIf(
        Node\Stmt\If_ $ifNode,
        Node\Stmt\ElseIf_ $elseIfNode,
        Scope $scope,
        PhpCondition $condition,
    ): void {

        $this->emitFactsAtNode(
            facts: $this->exclusionsBeforeElseIf($ifNode, $elseIfNode, $scope),
            scope: $scope,
            condition: $condition,
            node: $elseIfNode,
        );
    }

    public function emitForElse(
        Node\Stmt\If_ $ifNode,
        Scope $scope,
        PhpCondition $condition,
        ?int $filePos = null,
    ): void {

        $this->emitFactsAtPosition(
            facts: $this->factsForElse($ifNode, $scope),
            scope: $scope,
            condition: $condition,
            filePos: $filePos ?? $ifNode->getStartFilePos(),
        );
    }

    /**
     * @return list<NarrowingFact>
     */
    private function exclusionsBeforeElseIf(
        Node\Stmt\If_ $ifNode,
        Node\Stmt\ElseIf_ $currentElseIf,
        Scope $scope,
    ): array {

        $facts = $this->conditionFacts->extract($ifNode->cond, $scope, negated: true);

        foreach ($ifNode->elseifs as $elseIfNode) {
            if ($elseIfNode === $currentElseIf) {
                break;
            }

            $facts = array_merge(
                $facts,
                $this->conditionFacts->extract($elseIfNode->cond, $scope, negated: true),
            );
        }

        return $facts;
    }

    /**
     * @return list<NarrowingFact>
     */
    private function factsForElse(Node\Stmt\If_ $ifNode, Scope $scope): array
    {
        $facts = $this->conditionFacts->extract($ifNode->cond, $scope, negated: true);

        foreach ($ifNode->elseifs as $elseIfNode) {
            $facts = array_merge(
                $facts,
                $this->conditionFacts->extract($elseIfNode->cond, $scope, negated: true),
            );
        }

        return $facts;
    }

    /**
     * @param list<NarrowingFact> $facts
     */
    private function emitFactsAtNode(
        array $facts,
        Scope $scope,
        PhpCondition $condition,
        Node $node,
    ): void {

        $this->emitFactsAtPosition(
            facts: $facts,
            scope: $scope,
            condition: $condition,
            filePos: $node->getStartFilePos(),
        );
    }

    /**
     * @param list<NarrowingFact> $facts
     */
    private function emitFactsAtPosition(
        array $facts,
        Scope $scope,
        PhpCondition $condition,
        int $filePos,
    ): void {

        foreach ($facts as $fact) {
            $scope->variables->events->narrow(
                varName: $fact->target->baseVar,
                narrowing: $fact->narrowing,
                condition: $condition,
                filePos: $filePos,
                path: $fact->target->attributePath() ?? [],
            );
        }
    }
}
