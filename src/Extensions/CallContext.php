<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Narrowing\IsType;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\Narrowing\NarrowingFact;
use AutoDoc\Analyzer\Narrowing\NotType;
use AutoDoc\Analyzer\Narrowing\Target;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node;

/**
 * @template TNode of Node
 */
abstract class CallContext
{
    /**
     * @var list<NarrowingFact>
     */
    private array $narrowingFacts = [];

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
        $this->scope->variables->events->mutate($varName, $attributes, $this->startFilePos(), $this->endFilePos());
    }

    /**
     * Mutate attributes on the variable or literal path referenced by `$node`.
     * Does nothing for non-variable-backed expressions or dynamic paths.
     *
     * @param array<int|string, Type> $attributes
     */
    public function mutateExpression(Node $node, array $attributes): void
    {
        $target = Target::fromNode($node);

        if ($target === null) {
            return;
        }

        $this->scope->variables->events->mutate($target->baseVar, $attributes, $this->startFilePos(), $this->endFilePos(), $target->attributePath() ?? []);
    }

    /**
     * Assign a new type to a variable from an extension.
     */
    public function setVarType(string $varName, Type $type): void
    {
        $this->scope->variables->events->assign($varName, $type, $this->startFilePos(), $this->endFilePos());
    }

    /**
     * Record this call as contributing to the request body. Multiple recorded
     * bodies are combined conjunctively (see {@see Route::getRequestBodyType()}).
     */
    public function setRequestType(Type $type): void
    {
        $this->scope->extensions->recordRequestBodyType($type);
    }

    /**
     * Record a variable type narrowing from an extension while analyzing a
     * condition. These facts are collected by the narrowing analyzer, which
     * still owns boolean composition and event emission.
     */
    public function narrowVarType(string $varName, Type|Narrowing $type, bool $negated = false): void
    {
        $this->narrowTargetType(new Target($varName), $type, $negated);
    }

    /**
     * Record a narrowing for a variable, property, or literal-key array path.
     */
    public function narrowExpressionType(Node $node, Type|Narrowing $type, bool $negated = false): void
    {
        $target = Target::fromNode($node);

        if ($target !== null) {
            $this->narrowTargetType($target, $type, $negated);
        }
    }

    /**
     * @return list<NarrowingFact>
     */
    public function getNarrowingFacts(): array
    {
        return $this->narrowingFacts;
    }

    private function narrowTargetType(Target $target, Type|Narrowing $type, bool $negated = false): void
    {
        $narrowing = $type instanceof Narrowing
            ? $type
            : ($negated ? new NotType($type) : new IsType($type));

        $this->narrowingFacts[] = new NarrowingFact($target, $narrowing);
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
