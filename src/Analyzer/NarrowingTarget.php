<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

/**
 * A condition target: a variable, or a literal property/array-key path from it.
 */
final readonly class NarrowingTarget
{
    /**
     * @param list<int|string> $path
     */
    public function __construct(
        public string $baseVar,
        public array $path = [],
    ) {}

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
}
