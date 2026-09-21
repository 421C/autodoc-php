<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

class BranchPath
{
    /**
     * @param list<array{conditionId: int, branchIndex: int}> $segments
     */
    public function __construct(
        public readonly array $segments = [],
    ) {}

    /**
     * Whether this path is a prefix of (or equal to) the given path.
     * A mutation at this path is "visible" from $other.
     */
    public function isVisibleFrom(BranchPath $other): bool
    {
        $count = count($this->segments);

        if ($count > count($other->segments)) {
            return false;
        }

        for ($i = 0; $i < $count; $i++) {
            if ($this->segments[$i] !== $other->segments[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The index of the first segment where this path differs from $other,
     * or the shallower depth when one path is a prefix of the other.
     */
    public function commonPrefixDepth(self $other): int
    {
        $minDepth = min($this->depth(), $other->depth());

        for ($index = 0; $index < $minDepth; $index++) {
            if ($this->segments[$index] !== $other->segments[$index]) {
                return $index;
            }
        }

        return $minDepth;
    }

    public function push(int $conditionId, int $branchIndex): self
    {
        return new self([...$this->segments, ['conditionId' => $conditionId, 'branchIndex' => $branchIndex]]);
    }

    public function pop(): self
    {
        $segments = $this->segments;
        array_pop($segments);

        return new self($segments);
    }

    public function depth(): int
    {
        return count($this->segments);
    }
}
