<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\AttributeMutationApplier;
use AutoDoc\Analyzer\Flow\BranchPath;
use AutoDoc\Analyzer\Flow\ScopeEvent;
use AutoDoc\Analyzer\Flow\ScopeEventType;
use AutoDoc\Analyzer\Flow\ScopeEventVisibility;
use AutoDoc\Analyzer\Scope;
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

    /**
     * A non-empty `$readPath` resolves for a read of a single element along a
     * literal key path, so mutations targeting exactly that element stay
     * certain even when they were folded into a shared `itemType`.
     *
     * @param list<int|string> $readPath
     */
    public function resolve(array $readPath = []): Type
    {
        $cacheKey = $this->varName . ':' . $this->varStartFilePos . ($readPath === [] ? '' : ':' . json_encode($readPath));

        if (isset($this->scope->resolvedVariables[$cacheKey])) {
            return $this->scope->resolvedVariables[$cacheKey];
        }

        $events = $this->scope->eventLog->getEventsForVariable(
            $this->varName,
            $this->varStartFilePos,
            $this->readBranchPath,
        );

        if ($readPath !== [] && ! $this->eventsContainMutation($events)) {
            return $this->scope->resolvedVariables[$cacheKey] = $this->resolve();
        }

        $resolvedType = $this->resolveFromEvents($events, $readPath);

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
    private function eventsContainMutation(array $events): bool
    {
        return array_any(
            $events,
            fn (ScopeEvent $event) => $event->type === ScopeEventType::Mutate
                && (! empty($event->changes['attributes']) || isset($event->changes['dynamicAttribute'])),
        );
    }


    /**
     * @param ScopeEvent[] $events
     * @param list<int|string> $readPath
     */
    private function resolveFromEvents(array $events, array $readPath): ?Type
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
            $visibility = $this->scope->eventLog->getEventVisibility($event, $this->readBranchPath);

            if ($visibility === ScopeEventVisibility::Hidden) {
                continue;
            }

            $isCertain = $visibility === ScopeEventVisibility::Certain;

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

            } else if ($isCertain) {
                $pendingMutations[] = [$event, true];
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

            } else if ($event->type === ScopeEventType::Mutate
                && (! empty($event->changes['attributes']) || isset($event->changes['dynamicAttribute']))
            ) {
                $this->scope->withShapeMerging(function () use ($event, &$resolvedType, $isCertain, $mutationApplier, $readPath) {
                    $resolvedType = $mutationApplier->apply(
                        baseType: $resolvedType,
                        mutationPath: $event->changes['mutationPath'] ?? [],
                        attributes: $event->changes['attributes'] ?? [],
                        isCertain: $isCertain,
                        readPath: $readPath,
                        dynamicAttribute: $event->changes['dynamicAttribute'] ?? null,
                    );
                });

            } else if ($event->type === ScopeEventType::Narrow && isset($event->changes['narrowing'])) {
                if ($resolvedType !== null) {
                    $resolvedType = $narrowingApplier->applyPath(
                        base: $resolvedType,
                        path: $event->changes['narrowingPath'] ?? [],
                        narrowing: $event->changes['narrowing'],
                    );
                }
            }
        }

        return $resolvedType;
    }

}
