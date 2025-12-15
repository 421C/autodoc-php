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
    public function __construct(
        public readonly If_|While_|For_|Foreach_|Switch_|TryCatch $node,
    ) {}

    /**
     * @return list<ConditionBranch>
     */
    public function getConditionBranches(): array
    {
        $getPosition = fn ($node): array => [
            'startFilePos' => $node->getAttribute('startFilePos'),
            'endFilePos' => $node->getAttribute('endFilePos'),
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
