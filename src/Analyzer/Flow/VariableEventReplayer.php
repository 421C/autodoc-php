<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Narrowing\TypeNarrowingApplier;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final readonly class VariableEventReplayer
{
    private AttributeMutationApplier $mutationApplier;

    private TypeNarrowingApplier $narrowingApplier;

    private ArrayKeyUnsetApplier $unsetApplier;

    /**
     * @param list<int|string> $readPath
     */
    public function __construct(
        private Scope $scope,
        private array $readPath = [],
    ) {
        $this->mutationApplier = new AttributeMutationApplier($scope);
        $this->narrowingApplier = new TypeNarrowingApplier($scope);
        $this->unsetApplier = new ArrayKeyUnsetApplier($scope);
    }


    /**
     * @param list<ScopeEvent> $events
     */
    public function replay(array $events, BranchPath $readBranchPath, ?Type $type = null): ?Type
    {
        $index = 0;

        foreach ($events as $eventIndex => $event) {
            if ($event->type === ScopeEventType::Assign
                && isset($event->changes['type'])
                && $this->getVisibility($event, $readBranchPath) === ScopeEventVisibility::Certain
            ) {
                $type = $event->changes['type'];
                $index = $eventIndex + 1;
            }
        }

        $eventCount = count($events);

        while ($index < $eventCount) {
            $event = $events[$index];
            $visibility = $this->getVisibility($event, $readBranchPath);

            if ($visibility === ScopeEventVisibility::Uncertain) {
                $depth = $event->branchPath->commonPrefixDepth($readBranchPath);
                $conditionEvents = $this->getEventsOfExitedCondition($events, $index, $readBranchPath, $depth);
                $type = $this->joinBranches($conditionEvents, $depth, $type);
                $index += count($conditionEvents);

                continue;
            }

            if ($visibility === ScopeEventVisibility::Certain) {
                $type = $this->applyEvent($type, $event);
            }

            $index++;
        }

        return $type?->unwrapType($this->scope->config);
    }


    private function getVisibility(ScopeEvent $event, BranchPath $readBranchPath): ScopeEventVisibility
    {
        return $this->scope->variables->events->getEventVisibility($event, $readBranchPath);
    }


    /**
     * @param list<ScopeEvent> $events
     * @return non-empty-list<ScopeEvent>
     */
    private function getEventsOfExitedCondition(array $events, int $firstIndex, BranchPath $readBranchPath, int $depth): array
    {
        $firstEvent = $events[$firstIndex];
        $conditionId = $firstEvent->branchPath->segments[$depth]['conditionId'];
        $conditionEvents = [$firstEvent];

        foreach (array_slice($events, $firstIndex + 1) as $event) {
            $branchPath = $event->branchPath;

            if ($branchPath->depth() <= $depth
                || $branchPath->commonPrefixDepth($readBranchPath) !== $depth
                || $branchPath->segments[$depth]['conditionId'] !== $conditionId
            ) {
                break;
            }

            $conditionEvents[] = $event;
        }

        return $conditionEvents;
    }


    /**
     * @param non-empty-list<ScopeEvent> $conditionEvents
     */
    private function joinBranches(array $conditionEvents, int $depth, ?Type $typeBefore): ?Type
    {
        if (array_all($conditionEvents, fn (ScopeEvent $event) => $event->type === ScopeEventType::Narrow)) {
            return $typeBefore;
        }

        $condition = $this->scope->variables->events->getConditionById(
            $conditionEvents[0]->branchPath->segments[$depth]['conditionId'],
        );

        $typeBefore = $typeBefore?->unwrapType($this->scope->config);
        $eventsByBranch = [];

        foreach ($conditionEvents as $event) {
            $eventsByBranch[$event->branchPath->segments[$depth]['branchIndex']][] = $event;
        }

        $typesOfChangedValues = [];
        $typesOfReassignedValues = [];

        foreach ($eventsByBranch as $branchIndex => $branchEvents) {
            if ($condition?->branchHasBreakout($branchIndex)) {
                continue;
            }

            $branchType = $this->replay(
                events: $branchEvents,
                readBranchPath: new BranchPath(array_slice($branchEvents[0]->branchPath->segments, 0, $depth + 1)),
                type: $typeBefore,
            );

            if (array_any($branchEvents, fn (ScopeEvent $event) => $event->type === ScopeEventType::Assign)) {
                $typesOfReassignedValues[] = $branchType;

            } else if ($branchType !== null) {
                $typesOfChangedValues[] = $branchType;
            }
        }

        $autoVivifiedArray = $typesOfChangedValues === [] ? null : new ArrayType;
        $mayLeaveTypeUnchanged = $this->mayLeaveTypeUnchanged($condition, array_keys($eventsByBranch));

        $typeOfTheValueBefore = $this->scope->withShapeMerging(fn () => $this->union([
            $mayLeaveTypeUnchanged ? $typeBefore ?? $autoVivifiedArray : null,
            ...$typesOfChangedValues,
        ]));

        return $this->union([$typeOfTheValueBefore, ...$typesOfReassignedValues]);
    }


    /**
     * @param list<int> $branchIndexesWithEvents
     */
    private function mayLeaveTypeUnchanged(?PhpCondition $condition, array $branchIndexesWithEvents): bool
    {
        if ($condition === null || ! $condition->isExhaustive()) {
            return true;
        }

        for ($branchIndex = 0; $branchIndex < $condition->getBranchCount(); $branchIndex++) {
            if (! in_array($branchIndex, $branchIndexesWithEvents, true) && ! $condition->branchHasBreakout($branchIndex)) {
                return true;
            }
        }

        return false;
    }


    private function applyEvent(?Type $type, ScopeEvent $event): ?Type
    {
        if ($event->type === ScopeEventType::Assign) {
            return $event->changes['type'] ?? $type;
        }

        $type = $type?->unwrapType($this->scope->config);

        if ($event->type === ScopeEventType::Mutate) {
            return $this->scope->withShapeMerging(fn () => $this->mutationApplier->apply(
                baseType: $type,
                mutationPath: $event->changes['mutationPath'] ?? [],
                attributes: $event->changes['attributes'] ?? [],
                isCertain: true,
                readPath: $this->readPath,
                dynamicAttribute: $event->changes['dynamicAttribute'] ?? null,
                dynamicKey: $event->changes['dynamicKey'] ?? null,
            ));
        }

        if ($type === null) {
            return null;
        }

        if ($event->type === ScopeEventType::Narrow && isset($event->changes['narrowing'])) {
            return $this->narrowingApplier->applyPath(
                base: $type,
                path: $event->changes['narrowingPath'] ?? [],
                narrowing: $event->changes['narrowing'],
            );
        }

        if ($event->type === ScopeEventType::Unset && isset($event->changes['unsetPath'])) {
            return $this->unsetApplier->apply($type, $event->changes['unsetPath'], isCertain: true);
        }

        return $type;
    }


    /**
     * @param list<?Type> $types
     */
    private function union(array $types): ?Type
    {
        $types = array_values(array_filter($types));

        if (count($types) <= 1) {
            return $types[0] ?? null;
        }

        return new UnionType($types)->unwrapType($this->scope->config);
    }
}
