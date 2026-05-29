<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\DataTypes\Type;

class ScopeEventLog
{
    /** @var ScopeEvent[] */
    private array $events = [];

    private BranchPath $currentBranchPath;

    /**
     * Tracks branch path transitions over file positions.
     *
     * @var list<array{filePos: int, branchPath: BranchPath, condition: ?PhpCondition, branchIndex: ?int}>
     */
    private array $branchTransitions = [];

    public function __construct()
    {
        $this->currentBranchPath = new BranchPath;
    }

    public function getCurrentBranchPath(): BranchPath
    {
        return $this->currentBranchPath;
    }

    /**
     * Maps conditionId to PhpCondition for breakout checks.
     *
     * @var array<int, PhpCondition>
     */
    private array $conditions = [];

    public function enterBranch(int $conditionId, int $branchIndex, int $filePos = 0, ?PhpCondition $condition = null): void
    {
        $this->currentBranchPath = $this->currentBranchPath->push($conditionId, $branchIndex);
        $this->branchTransitions[] = ['filePos' => $filePos, 'branchPath' => $this->currentBranchPath, 'condition' => $condition, 'branchIndex' => $branchIndex];

        if ($condition !== null) {
            $this->conditions[$conditionId] = $condition;
        }
    }

    public function exitBranch(int $filePos = 0): void
    {
        $this->currentBranchPath = $this->currentBranchPath->pop();
        $this->branchTransitions[] = ['filePos' => $filePos, 'branchPath' => $this->currentBranchPath, 'condition' => null, 'branchIndex' => null];
    }

    public function getConditionById(int $conditionId): ?PhpCondition
    {
        return $this->conditions[$conditionId] ?? null;
    }

    /**
     * Determine which branch path was active at a given file position.
     */
    public function getBranchPathAtPosition(int $filePos): BranchPath
    {
        $result = new BranchPath;

        foreach ($this->branchTransitions as $transition) {
            if ($transition['filePos'] > $filePos) {
                break;
            }

            $result = $transition['branchPath'];
        }

        return $result;
    }

    public function assign(string $varName, Type $type, int $startFilePos, int $endFilePos = 0): void
    {
        $this->events[] = new ScopeEvent(
            type: ScopeEventType::Assign,
            varName: $varName,
            branchPath: $this->getBranchPathAtPosition($startFilePos),
            changes: ['type' => $type],
            startFilePos: $startFilePos,
            endFilePos: max($endFilePos, $startFilePos),
        );
    }

    /**
     * @param array<int|string, Type> $attributes
     */
    public function mutate(string $varName, array $attributes, int $startFilePos, int $endFilePos = 0): void
    {
        $this->events[] = new ScopeEvent(
            type: ScopeEventType::Mutate,
            varName: $varName,
            branchPath: $this->getBranchPathAtPosition($startFilePos),
            changes: ['attributes' => $attributes],
            startFilePos: $startFilePos,
            endFilePos: max($endFilePos, $startFilePos),
        );
    }

    public function narrow(string $varName, Narrowing $narrowing, PhpCondition $condition, int $filePos): void
    {
        $this->events[] = new ScopeEvent(
            type: ScopeEventType::Narrow,
            varName: $varName,
            branchPath: $this->currentBranchPath,
            changes: ['narrowing' => $narrowing],
            startFilePos: $filePos,
            endFilePos: $filePos,
            condition: $condition,
        );
    }

    /**
     * Narrow one property or literal-key element of `$varName`'s type.
     */
    public function narrowAttribute(string $varName, int|string $key, Narrowing $narrowing, PhpCondition $condition, int $filePos): void
    {
        $this->narrowAttributePath($varName, [$key], $narrowing, $condition, $filePos);
    }

    /**
     * Narrow a literal property/array-key path of `$varName`'s type.
     *
     * @param non-empty-list<int|string> $path
     */
    public function narrowAttributePath(string $varName, array $path, Narrowing $narrowing, PhpCondition $condition, int $filePos): void
    {
        $this->events[] = new ScopeEvent(
            type: ScopeEventType::NarrowAttribute,
            varName: $varName,
            branchPath: $this->currentBranchPath,
            changes: ['narrowingPath' => $path, 'narrowing' => $narrowing],
            startFilePos: $filePos,
            endFilePos: $filePos,
            condition: $condition,
        );
    }

    public function addEvent(ScopeEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Get all events for a given variable that occurred before the read position.
     * Uses endFilePos to ensure the entire assignment expression has completed.
     *
     * @return ScopeEvent[]
     */
    public function getEventsForVariable(string $varName, int $readFilePos, BranchPath $readBranchPath): array
    {
        $result = [];

        foreach ($this->events as $event) {
            if ($event->varName !== $varName) {
                continue;
            }

            // The read position must be after the end of the event's expression
            if ($readFilePos <= $event->endFilePos) {
                continue;
            }

            $result[] = $event;
        }

        return $result;
    }

    /**
     * @return ScopeEvent[]
     */
    public function getAllEvents(): array
    {
        return $this->events;
    }

    /**
     * Get all variable names that have been assigned.
     *
     * @return string[]
     */
    public function getAssignedVariableNames(): array
    {
        $names = [];

        foreach ($this->events as $event) {
            if ($event->type === ScopeEventType::Assign) {
                $names[$event->varName] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Check if a variable has any events.
     */
    public function hasVariable(string $varName): bool
    {
        return array_any($this->events, fn ($event) => $event->varName === $varName);
    }

    /**
     * Get all direct assignment types for a variable (used for parameter type inference).
     *
     * @return Type[]
     */
    public function getDirectAssignmentTypes(string $varName): array
    {
        $types = [];

        foreach ($this->events as $event) {
            if ($event->varName === $varName && $event->type === ScopeEventType::Assign && isset($event->changes['type'])) {
                $types[] = $event->changes['type'];
            }
        }

        return $types;
    }
}
