<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\BranchPath;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Analyzer\ScopeEvent;
use AutoDoc\Analyzer\ScopeEventType;

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
                $this->scope->withShapeMerging(function () use ($event, &$resolvedType, $isCertain) {
                    foreach ($event->changes['attributes'] as $key => $attributeType) {
                        $resolvedType = $this->mergeAttribute($resolvedType, $key, $attributeType, $isCertain);
                    }
                });

            } else if ($event->type === ScopeEventType::Narrow && isset($event->changes['narrowing'])) {
                if ($resolvedType !== null) {
                    $resolvedType = $event->changes['narrowing']->apply($resolvedType, $this->scope);
                }

            } else if ($event->type === ScopeEventType::NarrowAttribute
                && isset($event->changes['narrowing'])
                && isset($event->changes['narrowingPath'])
            ) {
                if ($resolvedType !== null) {
                    $resolvedType = $this->applyAttributeNarrowing(
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
     * Apply a narrowing to a literal attribute path, leaving the rest untouched.
     * Distributes over unions.
     *
     * @param non-empty-list<int|string> $path
     */
    private function applyAttributeNarrowing(Type $base, array $path, Narrowing $narrowing): Type
    {
        if ($base instanceof UnionType) {
            $base = clone $base;
            $base->types = array_map(
                fn (Type $type): Type => $this->applyAttributeNarrowing($type, $path, $narrowing),
                $base->types,
            );

            return $base->unwrapType($this->scope->config);
        }

        $key = array_shift($path);

        if ($base instanceof ObjectType) {
            $current = $this->resolveObjectPropertyType($base, $key);

            if ($current === null) {
                return $base;
            }

            $base = clone $base;
            $base->properties[(string) $key] = $path === []
                ? $narrowing->apply($current, $this->scope)
                : $this->applyAttributeNarrowing($current, $path, $narrowing);

            return $base;
        }

        if ($base instanceof ArrayType) {
            $current = $base->shape[$key] ?? $base->itemType;

            if ($current === null) {
                return $base;
            }

            $base = clone $base;
            $current = $current->unwrapType($this->scope->config);
            $base->shape[$key] = $path === []
                ? $narrowing->apply($current, $this->scope)
                : $this->applyAttributeNarrowing($current, $path, $narrowing);

            return $base;
        }

        return $base;
    }


    private function resolveObjectPropertyType(ObjectType $objectType, int|string $key): ?Type
    {
        $keyString = (string) $key;

        if (isset($objectType->properties[$keyString])) {
            return $objectType->properties[$keyString]->unwrapType($this->scope->config);
        }

        if ($objectType->className !== null) {
            return $this->scope->getPhpClass($objectType->className)->getProperty($keyString)?->unwrapType($this->scope->config);
        }

        return null;
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


    private function mergeAttribute(?Type $baseType, int|string $key, Type $attributeType, bool $isCertain): Type
    {
        if ($isCertain) {
            $attributeType = $this->setNestedAttributeAsRequired($attributeType);
        }

        $potentialTypes = $baseType instanceof UnionType ? $baseType->types : array_filter([$baseType]);
        $typesWithAddedAttribute = [];
        $counter = count($potentialTypes);

        for ($i = 0; $i < $counter; $i++) {
            if ($potentialTypes[$i] instanceof ObjectType) {
                $potentialTypes[$i] = clone $potentialTypes[$i];
                $keyString = (string) $key;

                if (isset($potentialTypes[$i]->properties[$keyString])) {
                    if ($isCertain && (!$attributeType instanceof ArrayType && !$attributeType instanceof ObjectType)) {
                        $potentialTypes[$i]->properties[$keyString] = $attributeType;

                    } else {
                        $potentialTypes[$i]->properties[$keyString] = new UnionType([
                            $potentialTypes[$i]->properties[$keyString],
                            $attributeType,
                        ])->unwrapType($this->scope->config)->unwrapType($this->scope->config);
                    }

                } else {
                    $potentialTypes[$i]->properties[$keyString] = $attributeType->setRequired($isCertain);
                }

                $typesWithAddedAttribute[] = $potentialTypes[$i];

            } else if ($potentialTypes[$i] instanceof ArrayType) {
                $potentialTypes[$i] = clone $potentialTypes[$i];

                if (isset($potentialTypes[$i]->shape[$key])) {
                    if ($isCertain && (!$attributeType instanceof ArrayType && !$attributeType instanceof ObjectType)) {
                        $potentialTypes[$i]->shape[$key] = $attributeType;

                    } else {
                        $potentialTypes[$i]->shape[$key] = new UnionType([
                            $potentialTypes[$i]->shape[$key],
                            $attributeType,
                        ])->unwrapType($this->scope->config)->unwrapType($this->scope->config);
                    }

                } else {
                    $potentialTypes[$i]->addItemToArray($key, $attributeType->setRequired($isCertain));
                }

                $typesWithAddedAttribute[] = $potentialTypes[$i];
            }
        }

        if ($isCertain) {
            if (empty($typesWithAddedAttribute)) {
                $baseType = new ArrayType;
                $baseType->addItemToArray($key, $attributeType->setRequired(true));

            } else {
                $baseType = new UnionType($typesWithAddedAttribute)->unwrapType($this->scope->config);
            }

        } else {
            if (empty($typesWithAddedAttribute)) {
                $arrayType = new ArrayType;
                $arrayType->addItemToArray($key, $attributeType);

                $baseType = new UnionType([...$potentialTypes, $arrayType])->unwrapType($this->scope->config);

            } else {
                $baseType = new UnionType($potentialTypes)->unwrapType($this->scope->config);
            }
        }

        return $baseType;
    }


    private function setNestedAttributeAsRequired(Type $type): Type
    {
        if ($type instanceof ArrayType) {
            $type->shape = array_map($this->setNestedAttributeAsRequired(...), $type->shape);
            $type->itemType?->setRequired(true);
        }

        return $type->setRequired(true);
    }
}
