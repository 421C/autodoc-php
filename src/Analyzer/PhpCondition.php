<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;

/**
 * @phpstan-type ConditionBranch = array{
 *     startFilePos: int,
 *     endFilePos: int,
 *     breakOutNode: ?Node,
 * }
 */
class PhpCondition
{
    private static int $nextId = 0;

    public readonly int $id;

    private readonly BranchBreakout $branchBreakout;

    /**
     * @var ?list<ConditionBranch>
     */
    private ?array $conditionBranches = null;

    public function __construct(
        public readonly If_|While_|For_|Foreach_|Switch_|TryCatch|Match_|Ternary $node,
        Scope $scope,
    ) {
        $this->id = self::$nextId++;
        $this->branchBreakout = $scope->getBranchBreakout();
    }

    public static function resetIdCounter(): void
    {
        self::$nextId = 0;
    }

    /**
     * Returns the number of branches in this condition.
     */
    public function getBranchCount(): int
    {
        if ($this->node instanceof If_) {
            $count = 1 + count($this->node->elseifs);

            if ($this->node->else !== null) {
                $count++;
            }

            return $count;
        }

        if ($this->node instanceof Switch_) {
            return count($this->node->cases);
        }

        if ($this->node instanceof TryCatch) {
            $count = 1 + count($this->node->catches);

            if ($this->node->finally !== null) {
                $count++;
            }

            return $count;
        }

        if ($this->node instanceof Match_) {
            return count($this->node->arms);
        }

        if ($this->node instanceof Ternary) {
            return 2;
        }

        return 1;
    }

    /**
     * Whether this condition covers all possible paths (has an else clause).
     */
    public function isExhaustive(): bool
    {
        if ($this->node instanceof If_) {
            return $this->node->else !== null;
        }

        if ($this->node instanceof Switch_) {
            foreach ($this->node->cases as $case) {
                if ($case->cond === null) {
                    return true;
                }
            }
        }

        return $this->node instanceof Ternary;
    }

    /**
     * Check if a specific branch index always breaks out (return/exit/throw).
     */
    public function branchHasBreakout(int $branchIndex): bool
    {
        $branches = $this->getConditionBranches();

        if (isset($branches[$branchIndex])) {
            return $branches[$branchIndex]['breakOutNode'] !== null;
        }

        return false;
    }

    /**
     * Check whether every branch of this condition breaks out (return/exit/throw).
     */
    public function allBranchesBreakOut(): bool
    {
        $branchCount = $this->getBranchCount();

        for ($i = 0; $i < $branchCount; $i++) {
            if (! $this->branchHasBreakout($i)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<ConditionBranch>
     */
    public function getConditionBranches(): array
    {
        return $this->conditionBranches ??= $this->resolveConditionBranches();
    }

    /**
     * @return list<ConditionBranch>
     */
    private function resolveConditionBranches(): array
    {
        if ($this->node instanceof If_) {
            $positions = array_values([
                $this->getBranchFromStatements($this->node->stmts, $this->node),
                ...array_map(
                    fn (Node\Stmt\ElseIf_ $branch) => $this->getBranchFromStatements($branch->stmts, $branch),
                    $this->node->elseifs,
                ),
            ]);

            if ($this->node->else !== null) {
                $positions[] = $this->getBranchFromStatements($this->node->else->stmts, $this->node->else);
            }

            return $positions;
        }

        if ($this->node instanceof Switch_) {
            return array_values(array_map(
                fn (Node\Stmt\Case_ $branch) => $this->getBranchFromStatements($branch->stmts, $branch),
                $this->node->cases,
            ));
        }

        if ($this->node instanceof TryCatch) {
            $positions = [
                $this->getBranchFromStatements($this->node->stmts, $this->node),
            ];

            foreach ($this->node->catches as $catch) {
                $positions[] = $this->getBranchFromStatements($catch->stmts, $catch);
            }

            if ($this->node->finally !== null) {
                $positions[] = $this->getBranchFromStatements($this->node->finally->stmts, $this->node->finally);
            }

            return $positions;
        }

        if ($this->node instanceof Match_) {
            return array_values(array_map(
                fn (Node\MatchArm $arm) => $this->getBranchFromStatements([$arm->body], $arm),
                $this->node->arms,
            ));
        }

        if ($this->node instanceof Ternary) {
            return [
                $this->getBranchFromStatements([$this->node->if ?? $this->node->cond], $this->node),
                $this->getBranchFromStatements([$this->node->else], $this->node),
            ];
        }

        if ($this->node instanceof While_ // @phpstan-ignore booleanOr.alwaysTrue
            || $this->node instanceof For_
            || $this->node instanceof Foreach_ // @phpstan-ignore instanceof.alwaysTrue
        ) {
            return [
                $this->getBranchFromStatements($this->node->stmts, $this->node),
            ];
        }

        return [ // @phpstan-ignore deadCode.unreachable
            $this->getBranchFromStatements([], $this->node),
        ];
    }

    private function requireFilePosAttribute(Node $node, string $attribute): int
    {
        $value = $node->getAttribute($attribute);

        if (! is_int($value)) {
            throw new \LogicException(sprintf('Expected %s to be present on node of type %s.', $attribute, $node::class));
        }

        return $value;
    }

    /**
     * @param Node[] $statements
     * @return ConditionBranch
     */
    private function getBranchFromStatements(array $statements, Node $fallbackNode): array
    {
        if ($statements) {
            $firstStatement = reset($statements);
            $lastStatement = end($statements);

            $startFilePos = $this->requireFilePosAttribute($firstStatement, 'startFilePos');
            $endFilePos = $this->requireFilePosAttribute($lastStatement, 'endFilePos');

        } else {
            $startFilePos = $this->requireFilePosAttribute($fallbackNode, 'startFilePos');
            $endFilePos = $this->requireFilePosAttribute($fallbackNode, 'endFilePos');
        }

        return [
            'startFilePos' => $startFilePos,
            'endFilePos' => $endFilePos,
            'breakOutNode' => $this->branchBreakout->getBreakOutNodeFromStatements($statements),
        ];
    }
}
