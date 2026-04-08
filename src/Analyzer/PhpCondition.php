<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
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

    public function __construct(
        public readonly If_|While_|For_|Foreach_|Switch_|TryCatch $node,
    ) {
        $this->id = self::$nextId++;
    }

    public static function resetIdCounter(): void
    {
        self::$nextId = 0;
    }

    /**
     * Returns the number of branches in this condition.
     * For if/elseif/else: 1 (if) + N (elseif) + 1? (else)
     * For all others: 1
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

        return false;
    }

    /**
     * Check if a specific branch index has a breakout (return/exit).
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
     * @return list<ConditionBranch>
     */
    public function getConditionBranches(): array
    {
        $getPosition = fn (Node $node): array => [
            'startFilePos' => $this->requireFilePosAttribute($node, 'startFilePos'),
            'endFilePos' => $this->requireFilePosAttribute($node, 'endFilePos'),
            'breakOutNode' => $this->getBreakOutNode($node),
        ];

        if ($this->node instanceof If_) {
            $positions = array_values([
                $getPosition($this->node),
                ...array_map($getPosition, $this->node->elseifs),
            ]);

            if ($this->node->else !== null) {
                $positions[] = $getPosition($this->node->else);
            }

            return $positions;
        }

        return [
            $getPosition($this->node),
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

    private function getBreakOutNode(Node $node): ?Node
    {
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->{$subNodeName};

            if ($subNode instanceof Node) {
                if ($subNode instanceof Return_) {
                    return $subNode;
                }

                if ($subNode instanceof Expression && $subNode->expr instanceof Exit_) {
                    return $subNode->expr;
                }
            }

            if (is_array($subNode)) {
                foreach ($subNode as $subNodeItem) {
                    if ($subNodeItem instanceof Node) {
                        if ($subNodeItem instanceof Return_) {
                            return $subNodeItem;
                        }

                        if ($subNodeItem instanceof Expression && $subNodeItem->expr instanceof Exit_) {
                            return $subNodeItem->expr;
                        }
                    }
                }
            }
        }

        return null;
    }
}
