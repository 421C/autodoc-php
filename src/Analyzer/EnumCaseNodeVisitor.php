<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use Override;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;


class EnumCaseNodeVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private Scope $scope,
    ) {}

    /**
     * @var array<int|string, string>
     */
    public array $caseDescriptions = [];

    /**
     * @return null|NodeVisitor::*
     */
    #[Override]
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Enum_) {
            foreach ($node->stmts as $enumCase) {
                if (! ($enumCase instanceof Node\Stmt\EnumCase)) {
                    continue;
                }

                $phpDoc = null;

                foreach ($enumCase->getComments() as $comment) {
                    if ($comment instanceof Comment\Doc) {
                        $phpDoc = new PhpDoc($comment->getText(), $this->scope);
                    }
                }

                if ($enumCase->expr instanceof Node\Scalar\Int_
                    || $enumCase->expr instanceof Node\Scalar\String_
                ) {
                    $caseValue = $enumCase->expr->value;

                    $this->caseDescriptions[$caseValue] = $phpDoc?->getText() ?? (string) $enumCase->name;
                }
            }

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}
