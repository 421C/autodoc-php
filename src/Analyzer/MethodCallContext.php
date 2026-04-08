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

    /**
     * Mutate a variable's attributes from an extension.
     * Use this to add properties/keys to a variable's type.
     *
     * @param array<int|string, Type> $attributes
     */
    public function mutateVar(string $varName, array $attributes): void
    {
        /** @var int */
        $startFilePos = $this->node->getAttribute('startFilePos');

        /** @var int */
        $endFilePos = $this->node->getAttribute('endFilePos');

        $this->scope->eventLog->mutate($varName, $attributes, $startFilePos, $endFilePos);
    }

    /**
     * Assign a new type to a variable from an extension.
     */
    public function setVarType(string $varName, Type $type): void
    {
        /** @var int */
        $startFilePos = $this->node->getAttribute('startFilePos');

        /** @var int */
        $endFilePos = $this->node->getAttribute('endFilePos');

        $this->scope->eventLog->assign($varName, $type, $startFilePos, $endFilePos);
    }
}
