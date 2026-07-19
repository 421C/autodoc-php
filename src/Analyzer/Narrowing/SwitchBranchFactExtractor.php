<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use PhpParser\Node;


final readonly class SwitchBranchFactExtractor
{
    /**
     * @return list<NarrowingFact>
     */
    public function factsForCaseBody(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $caseNode,
        Scope $scope,
    ): array {

        $subjectTarget = Target::fromNode($switchNode->cond);

        if ($subjectTarget === null || $caseNode->stmts === []) {
            return [];
        }

        $narrowing = $this->narrowingForCases(
            cases: $this->fallThroughGroupEndingAt($switchNode, $caseNode),
            scope: $scope,
        );

        return $narrowing === null ? [] : [new NarrowingFact($subjectTarget, $narrowing)];
    }

    /**
     * @return list<NarrowingFact>
     */
    public function exclusionsBeforeCase(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $currentCase,
        Scope $scope,
    ): array {

        $subjectTarget = Target::fromNode($switchNode->cond);

        if ($subjectTarget === null) {
            return [];
        }

        $facts = [];

        foreach ($switchNode->cases as $case) {
            if ($case === $currentCase) {
                break;
            }

            if ($case->cond === null
                || ! $this->isLiteralScalar($case->cond)
                || $this->canFallThrough($switchNode, $case, $currentCase, $scope)
            ) {
                continue;
            }

            $facts[] = new NarrowingFact($subjectTarget, new NotType($scope->resolveType($case->cond)));
        }

        return $facts;
    }

    /**
     * @return list<Node\Stmt\Case_>
     */
    private function fallThroughGroupEndingAt(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $bodyCase,
    ): array {

        $fallThroughCases = [];

        foreach ($switchNode->cases as $case) {
            if ($case->stmts !== [] && $case !== $bodyCase) {
                $fallThroughCases = [];

                continue;
            }

            $fallThroughCases[] = $case;

            if ($case === $bodyCase) {
                break;
            }
        }

        return $fallThroughCases;
    }

    /**
     * @param list<Node\Stmt\Case_> $cases
     */
    private function narrowingForCases(array $cases, Scope $scope): ?Narrowing
    {
        $caseNarrowings = [];

        foreach ($cases as $case) {
            if ($case->cond === null || ! $this->isLiteralScalar($case->cond)) {
                return null;
            }

            $caseNarrowings[] = new IsType($scope->resolveType($case->cond));
        }

        if ($caseNarrowings === []) {
            return null;
        }

        return count($caseNarrowings) === 1 ? $caseNarrowings[0] : new AnyOf($caseNarrowings);
    }

    private function canFallThrough(
        Node\Stmt\Switch_ $switchNode,
        Node\Stmt\Case_ $fromCase,
        Node\Stmt\Case_ $toCase,
        Scope $scope,
    ): bool {

        $reachedFromCase = false;

        foreach ($switchNode->cases as $case) {
            if ($case === $fromCase) {
                $reachedFromCase = true;
            }

            if (! $reachedFromCase) {
                continue;
            }

            if ($case === $toCase) {
                return true;
            }

            if ($this->caseTerminatesControlFlow($case, $scope)) {
                return false;
            }
        }

        return true;
    }

    private function caseTerminatesControlFlow(Node\Stmt\Case_ $case, Scope $scope): bool
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
            || $scope->getBranchBreakout()->statementBreaksOut($lastStatement);
    }

    private function isLiteralScalar(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
            || ($node instanceof Node\Expr\ConstFetch && in_array($node->name->toString(), ['true', 'false'], true));
    }
}
