<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\UnresolvedPhpDocType;
use Override;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;


class ClassConstructorPropertyVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private Scope $scope,
    ) {}

    /**
     * @var array<string, UnresolvedPhpDocType>
     */
    public array $promotedProperties = [];

    /**
     * @return null|NodeVisitor::*
     */
    #[Override]
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            if ($node->name->toString() !== '__construct') {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            foreach ($node->params as $param) {
                if (! $param->isPromoted()
                    || ! ($param->var instanceof Variable)
                    || ! is_string($param->var->name)
                ) {
                    continue;
                }

                foreach ($param->getComments() as $comment) {
                    if ($comment instanceof Comment\Doc) {
                        $paramPhpDoc = new PhpDoc($comment->getText(), $this->scope);

                        foreach ($paramPhpDoc->getVarTags() as $var) {
                            [$varName, $varType] = $var;

                            if ($varName && $param->var->name !== $varName) {
                                continue;
                            }

                            $this->promotedProperties[$param->var->name] = $varType;
                        }
                    }
                }
            }

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}
