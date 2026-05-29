<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

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
     * Whether this path and $other share the same parent condition
     * but are in different branches (sibling branches of an if/else).
     */
    public function isSiblingOf(BranchPath $other): bool
    {
        $count = count($this->segments);

        if ($count === 0 || $count !== count($other->segments)) {
            return false;
        }

        for ($i = 0; $i < $count - 1; $i++) {
            if ($this->segments[$i] !== $other->segments[$i]) {
                return false;
            }
        }

        return $this->segments[$count - 1]['conditionId'] === $other->segments[$count - 1]['conditionId']
            && $this->segments[$count - 1]['branchIndex'] !== $other->segments[$count - 1]['branchIndex'];
    }

    /**
     * Whether this path is deeper than (strictly contained within) the given path.
     */
    public function isDeeperThan(BranchPath $other): bool
    {
        return count($this->segments) > count($other->segments)
            && $other->isVisibleFrom($this);
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
