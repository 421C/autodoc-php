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
     * @var array{name: string, value: int|string, description?: string}[]
     */
    public array $enumCases = [];

    public ?string $backingType = null;

    /**
     * @return null|NodeVisitor::*
     */
    #[Override]
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Enum_) {
            $this->backingType = $node->scalarType?->name;

            foreach ($node->stmts as $enumCase) {
                if (! ($enumCase instanceof Node\Stmt\EnumCase)) {
                    continue;
                }

                $phpDoc = null;

                foreach ($enumCase->getComments() as $comment) {
                    if ($comment instanceof Comment\Doc) {
                        $phpDoc = new PhpDoc($comment->getText(), $this->scope);

                        if ($phpDoc->getAutodocIgnoreTag()) {
                            continue 2;
                        }
                    }
                }

                if ($enumCase->expr instanceof Node\Scalar\Int_
                    || $enumCase->expr instanceof Node\Scalar\String_
                ) {
                    $value = $enumCase->expr->value;

                } else if ($enumCase->expr instanceof Node\Expr\UnaryMinus
                    && $enumCase->expr->expr instanceof Node\Scalar\Int_
                ) {
                    $value = -$enumCase->expr->expr->value;

                } else if ($enumCase->expr === null) {
                    $value = (string) $enumCase->name;

                } else {
                    continue;
                }

                $case = [
                    'name' => (string) $enumCase->name,
                    'value' => $value,
                ];

                $description = $phpDoc?->getText();

                if ($description) {
                    $case['description'] = $description;
                }

                $this->enumCases[] = $case;
            }

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}
