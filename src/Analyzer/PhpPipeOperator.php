<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Pipe;

class PhpPipeOperator
{
    public function __construct(
        private Pipe $pipeNode,
        private Scope $scope,
    ) {}

    public function resolveType(): Type
    {
        $leftType = $this->scope->resolveType($this->pipeNode->left);

        if ($this->pipeNode->right instanceof Node\Expr\FuncCall
            || $this->pipeNode->right instanceof Node\Expr\StaticCall
            || $this->pipeNode->right instanceof Node\Expr\MethodCall
        ) {
            $hasVariadicPlaceholder = isset($this->pipeNode->right->args[0])
                && $this->pipeNode->right->args[0] instanceof Node\VariadicPlaceholder;

            if ($hasVariadicPlaceholder) {
                $funcCallNode = clone $this->pipeNode->right;
                $funcCallNode->args = [new Node\Arg($this->pipeNode->left)];

                return $this->scope->resolveType($funcCallNode);
            }
        }

        $rightType = $this->scope->resolveType($this->pipeNode->right);

        if ($rightType instanceof StringType && is_string($rightType->value)) {
            $functionName = $rightType->value;

            $funcCallNode = new Node\Expr\FuncCall(
                new Node\Name($functionName),
                [new Node\Arg($this->pipeNode->left)]
            );

            return $this->scope->resolveType($funcCallNode);
        }

        if ($rightType instanceof CallableType) {
            return $rightType->getReturnType(
                args: [
                    new PhpFunctionArgument($this->scope->resolveType($this->pipeNode->left), $this->scope),
                ],
                callerNode: $this->pipeNode->right,
            );
        }

        return $rightType;
    }
}
