<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;


class StaticCallContext
{
    public readonly string $methodName;
    public readonly ArgumentList $argTypes;

    /** @var ?class-string */
    public readonly ?string $className;

    public function __construct(
        public readonly StaticCall $node,
        public readonly Scope $scope,
    ) {
        $this->methodName = (string) $scope->getRawValueFromNode($node->name);
        $this->className = $node->class instanceof Node\Name ? $scope->getResolvedClassName($node->class) : null;
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }

    /**
     * Mutate a variable's attributes from an extension.
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
