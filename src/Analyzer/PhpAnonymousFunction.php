<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use PhpParser\Node;
use PhpParser\NodeTraverser;

class PhpAnonymousFunction
{
    public function __construct(
        private Node\Expr\Closure|Node\Expr\ArrowFunction $node,
        private Scope $scope,
    ) {}


    /**
     * @param PhpFunctionArgument[] $args
     */
    public function resolveReturnType(array $args = []): Type
    {
        $traverser = new NodeTraverser;

        $nodeVisitor = new FunctionNodeVisitor(
            scope: $this->scope->createChildScope(),
            parentScope: $this->scope,
            analyzeReturnValue: true,
            args: $args,
        );

        $traverser->addVisitor($nodeVisitor);
        $traverser->traverse([$this->node]);

        $returnType = new UnionType($nodeVisitor->returnTypes);

        return $returnType->unwrapType($this->scope->config);
    }
}
