<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use PhpParser\Node;

/**
 * @template TNode of Node
 */
abstract class CallContext
{
    public function __construct(
        /** @var TNode */
        public readonly Node $node,
        public readonly Scope $scope,
    ) {}

    /**
     * Mutate a variable's attributes from an extension.
     * Use this to add properties/keys to a variable's type.
     *
     * @param array<int|string, Type> $attributes
     */
    public function mutateVar(string $varName, array $attributes): void
    {
        $this->scope->eventLog->mutate($varName, $attributes, $this->startFilePos(), $this->endFilePos());
    }

    /**
     * Assign a new type to a variable from an extension.
     */
    public function setVarType(string $varName, Type $type): void
    {
        $this->scope->eventLog->assign($varName, $type, $this->startFilePos(), $this->endFilePos());
    }

    private function startFilePos(): int
    {
        /** @var int */
        return $this->node->getAttribute('startFilePos');
    }

    private function endFilePos(): int
    {
        /** @var int */
        return $this->node->getAttribute('endFilePos');
    }
}
