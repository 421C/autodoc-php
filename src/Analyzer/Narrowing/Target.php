<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use PhpParser\Node;

/**
 * A condition target: a variable, or a literal property/array-key path from it.
 */
final readonly class Target
{
    /**
     * @param list<int|string> $path
     */
    public function __construct(
        public string $baseVar,
        public array $path = [],
    ) {}

    /**
     * Resolve a condition expression to a narrowing target. Supports a plain
     * variable (`$x`) or a literal property/array-key path from one
     * (`$x->a['b']`). Anything else yields null.
     */
    public static function fromNode(Node $node): ?self
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return new self($node->name);
        }

        $path = [];
        $currentNode = $node;

        while ($currentNode instanceof Node\Expr\PropertyFetch
            || $currentNode instanceof Node\Expr\NullsafePropertyFetch
            || $currentNode instanceof Node\Expr\ArrayDimFetch
        ) {
            if ($currentNode instanceof Node\Expr\PropertyFetch || $currentNode instanceof Node\Expr\NullsafePropertyFetch) {
                if (! $currentNode->name instanceof Node\Identifier) {
                    return null;
                }

                $path[] = $currentNode->name->toString();
                $currentNode = $currentNode->var;

                continue;
            }

            if ($currentNode->dim === null) {
                return null;
            }

            $key = self::literalArrayKey($currentNode->dim);

            if ($key !== null) {
                $path[] = $key;
                $currentNode = $currentNode->var;

                continue;
            }

            return null;
        }

        if ($currentNode instanceof Node\Expr\Variable && is_string($currentNode->name) && $path !== []) {
            return new self($currentNode->name, array_reverse($path));
        }

        return null;
    }

    public function isAttribute(): bool
    {
        return $this->path !== [];
    }

    /**
     * @return non-empty-list<int|string>|null
     */
    public function attributePath(): ?array
    {
        return $this->path === [] ? null : $this->path;
    }

    /**
     * A stable identity used to group narrowings of the same target (e.g. when
     * combining the two operands of a logical OR).
     */
    public function id(): string
    {
        return $this->path === [] ? $this->baseVar : $this->baseVar . '::' . serialize($this->path);
    }

    private static function literalArrayKey(Node $node): int|string|null
    {
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
            return $node->value;
        }

        return null;
    }
}
