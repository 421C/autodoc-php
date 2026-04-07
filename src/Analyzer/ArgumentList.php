<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use Countable;
use PhpParser\Node;

/**
 * A lazy, scope-aware type collection. Types are resolved on first
 * access and cached for subsequent reads.
 */
class ArgumentList implements Countable
{
    public function __construct(
        private readonly Scope $scope,

        /**
         * @var array<Node\Arg|Node\VariadicPlaceholder> $argNodes
         */
        private array $argNodes = [],
    ) {}

    /** @var array<int, Type> */
    private array $resolved = [];

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $argNodes
     */
    public static function fromArgNodes(array $argNodes, Scope $scope): self
    {
        return new self($scope, $argNodes);
    }

    /**
     * @param list<Type> $types
     */
    public static function fromTypes(array $types, Scope $scope): self
    {
        $list = new self($scope);
        $list->resolved = $types;

        return $list;
    }


    public function get(int $index, bool $autoResolve = true): Type
    {
        if (isset($this->resolved[$index])) {
            return $this->resolved[$index];
        }

        $argNode = $this->argNodes[$index] ?? null;

        if ($argNode instanceof Node\Arg) {
            if ($autoResolve) {
                $this->resolved[$index] = $this->scope->resolveType($argNode->value);

                return $this->resolved[$index];
            }

            return new UnresolvedParserNodeType(node: $argNode->value, scope: $this->scope);
        }

        return new UnknownType;
    }



    public function findNamedIndex(string $name): ?int
    {
        foreach ($this->argNodes as $index => $argNode) {
            if ($argNode instanceof Node\Arg && $argNode->name?->name === $name) {
                return $index;
            }
        }

        return null;
    }


    public function has(int $index): bool
    {
        return isset($this->resolved[$index]) || isset($this->argNodes[$index]);
    }

    public function count(): int
    {
        return max(count($this->argNodes), count($this->resolved));
    }
}
