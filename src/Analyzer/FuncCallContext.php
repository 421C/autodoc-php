<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;


class FuncCallContext
{
    public readonly ?string $functionName;
    public readonly ArgumentList $argTypes;

    public function __construct(
        public readonly FuncCall $node,
        public readonly Scope $scope,
    ) {
        $rawName = $node->name instanceof Node\Name
            ? $node->name->name
            : $scope->getRawValueFromNode($node->name);

        $this->functionName = is_string($rawName) ? $rawName : null;
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
