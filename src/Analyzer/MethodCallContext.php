<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;


class MethodCallContext
{
    public readonly string $methodName;
    public readonly ArgumentList $argTypes;

    private ?Type $resolvedVarType = null;

    public function __construct(
        public readonly MethodCall|NullsafeMethodCall $node,
        public readonly Scope $scope,
    ) {
        $this->methodName = (string) $scope->getRawValueFromNode($node->name);
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }

    public function getVarType(): Type
    {
        return $this->resolvedVarType ??= $this->scope->resolveType($this->node->var);
    }
}
