<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\AttributeMutationApplier;
use AutoDoc\Analyzer\BranchPath;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Analyzer\ScopeEvent;
use AutoDoc\Analyzer\ScopeEventType;
use AutoDoc\Analyzer\TypeNarrowingApplier;

class UnresolvedVariableType extends UnresolvedType
{
    public function __construct(
        public readonly string $varName,
        public readonly Scope $scope,
        public readonly int $varStartFilePos,
        public readonly BranchPath $readBranchPath,
        public ?string $description = null,
    ) {}

    public function resolve(): Type
    {
        $cacheKey = $this->varName . ':' . $this->varStartFilePos;

        if (isset($this->scope->resolvedVariables[$cacheKey])) {
            return $this->scope->resolvedVariables[$cacheKey];
        }

        $events = $this->scope->eventLog->getEventsForVariable(
            $this->varName,
            $this->varStartFilePos,
            $this->readBranchPath,
        );

        $resolvedType = $this->resolveFromEvents($events);

        if (! $resolvedType) {
            $resolvedType = new UnknownType;
        }

        $resolvedType->addDescription($this->description);
        $resolvedType->examples = $this->examples ?: $resolvedType->examples;
        $resolvedType->required = $this->required ?: $resolvedType->required;

        $this->scope->resolvedVariables[$cacheKey] = $resolvedType;

        return $resolvedType;
    }


    /**
     * @param ScopeEvent[] $events
     */
    private function resolveFromEvents(array $events): ?Type
    {
        // Walk events forward, building up the type.
        // At each point, determine if the event is:
        //   1. Directly visible (same branch path or ancestor) → apply with certainty
        //   2. In a sibling branch → collect as uncertain alternative
        //   3. In a deeper/unrelated branch → skip

        $baseType = null;

        /** @var list<array{ScopeEvent, bool}> $pendingMutations */
        $pendingMutations = [];

        foreach ($events as $event) {
            $visibility = $this->getEventVisibility($event);

            if ($visibility === 'hidden') {
                continue;
            }

            $isCertain = $visibility === 'certain';

            if ($event->type === ScopeEventType::Assign) {
                if ($isCertain) {
                    // Certain assignment replaces everything before it
                    $baseType = $event->changes['type'] ?? null;
                    $pendingMutations = [];

                } else {
                    // Uncertain assignment: union with what we have
                    $pendingMutations[] = [$event, false];
                }

            } else if ($event->type === ScopeEventType::Mutate) {
                $pendingMutations[] = [$event, $isCertain];

            } else {
                // ScopeEventType::Narrow or ScopeEventType::NarrowAttribute
                if ($isCertain) {
                    $pendingMutations[] = [$event, true];
                }
            }
        }

        // Apply pending mutations to the base type
        $resolvedType = $baseType?->unwrapType($this->scope->config);
        $narrowingApplier = new TypeNarrowingApplier($this->scope);
        $mutationApplier = new AttributeMutationApplier($this->scope);

        foreach ($pendingMutations as [$event, $isCertain]) {
            if ($event->type === ScopeEventType::Assign && isset($event->changes['type'])) {
                if ($isCertain) {
                    $resolvedType = $event->changes['type']->unwrapType($this->scope->config);

                } else {
                    $resolvedType = new UnionType(array_values(array_filter([
                        $resolvedType,
                        $event->changes['type'],
                    ])))->unwrapType($this->scope->config);
                }

            } else if ($event->type === ScopeEventType::Mutate && ! empty($event->changes['attributes'])) {
                $this->scope->withShapeMerging(function () use ($event, &$resolvedType, $isCertain, $mutationApplier) {
                    $resolvedType = $mutationApplier->apply(
                        $resolvedType,
                        $event->changes['mutationPath'] ?? [],
                        $event->changes['attributes'],
                        $isCertain,
                    );
                });

            } else if ($event->type === ScopeEventType::Narrow && isset($event->changes['narrowing'])) {
                if ($resolvedType !== null) {
                    $resolvedType = $event->changes['narrowing']->apply($resolvedType, $this->scope);
                }

            } else if ($event->type === ScopeEventType::NarrowAttribute
                && isset($event->changes['narrowing'])
                && ! empty($event->changes['narrowingPath'])
            ) {
                if ($resolvedType !== null) {
                    $resolvedType = $narrowingApplier->applyAttributePath(
                        $resolvedType,
                        $event->changes['narrowingPath'],
                        $event->changes['narrowing'],
                    );
                }
            }
        }

        return $resolvedType;
    }

    /**
     * Determine how an event relates to the read position's branch path.
     *
     * @return 'certain'|'uncertain'|'hidden'
     */
    private function getEventVisibility(ScopeEvent $event): string
    {
        $eventPath = $event->branchPath;
        $readPath = $this->readBranchPath;

        // Event is at root level (no branch) — always visible and certain
        if ($eventPath->depth() === 0) {
            return 'certain';
        }

        // Event's path is a prefix of (or equal to) read path — visible and certain
        if ($eventPath->isVisibleFrom($readPath)) {
            return 'certain';
        }

        // Check if event is in a sibling branch
        $siblingInfo = $this->getSiblingBranchInfo($eventPath, $readPath);

        if ($siblingInfo !== null) {
            [$conditionId, $eventBranchIndex] = $siblingInfo;

            // If the event's branch has a breakout (return/exit), and the read is
            // OUTSIDE the condition (at a lower depth), the mutation is unreachable.
            if ($readPath->depth() < $eventPath->depth()) {
                $condition = $this->scope->eventLog->getConditionById($conditionId);

                if ($condition !== null && $condition->branchHasBreakout($eventBranchIndex)) {
                    return 'hidden';
                }
            }

            // Check if this is the only non-breaking branch in an exhaustive if/else.
            // If so, the mutation is certain (all other branches break out).
            $condition = $this->scope->eventLog->getConditionById($conditionId);

            if ($condition !== null && $condition->isExhaustive() && $readPath->depth() < $eventPath->depth()) {
                $branchCount = $condition->getBranchCount();
                $allOthersBreakOut = true;

                for ($bi = 0; $bi < $branchCount; $bi++) {
                    if ($bi !== $eventBranchIndex && ! $condition->branchHasBreakout($bi)) {
                        $allOthersBreakOut = false;
                        break;
                    }
                }

                if ($allOthersBreakOut) {
                    return 'certain';
                }
            }

            return 'uncertain';
        }

        // Event is in a deeper or unrelated branch — hidden
        return 'hidden';
    }


    /**
     * Check if the event is in a sibling branch and return the diverging condition info.
     *
     * @return array{int, int}|null [conditionId, eventBranchIndex] or null if not a sibling
     */
    private function getSiblingBranchInfo(BranchPath $eventPath, BranchPath $readPath): ?array
    {
        $eventSegments = $eventPath->segments;
        $readSegments = $readPath->segments;
        $minDepth = min(count($eventSegments), count($readSegments));

        for ($i = 0; $i < $minDepth; $i++) {
            if ($eventSegments[$i]['conditionId'] === $readSegments[$i]['conditionId']
                && $eventSegments[$i]['branchIndex'] !== $readSegments[$i]['branchIndex']
            ) {
                return [$eventSegments[$i]['conditionId'], $eventSegments[$i]['branchIndex']];
            }

            if ($eventSegments[$i] !== $readSegments[$i]) {
                return null;
            }
        }

        // Event is deeper than read path — check if it's in a branch of a condition
        // that the read position is after (read has exited the condition)
        if (count($eventSegments) > count($readSegments)) {
            $divergeLevel = count($readSegments);
            $conditionId = $eventSegments[$divergeLevel]['conditionId'];
            $eventBranchIndex = $eventSegments[$divergeLevel]['branchIndex'];

            return [$conditionId, $eventBranchIndex];
        }

        return null;
    }
}
