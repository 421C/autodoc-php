<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Flow\BranchPath;
use AutoDoc\Analyzer\Flow\ScopeEvent;
use AutoDoc\Analyzer\Flow\ScopeEventType;
use AutoDoc\Analyzer\Flow\VariableEventReplayer;
use AutoDoc\Analyzer\Scope;

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

        if (isset($this->scope->variables->resolvedTypes[$cacheKey])) {
            return $this->scope->variables->resolvedTypes[$cacheKey];
        }

        $events = $this->scope->variables->events->getEventsForVariable(
            $this->varName,
            $this->varStartFilePos,
            $this->readBranchPath,
        );

        if ($readPath !== [] && ! $this->eventsContainMutation($events)) {
            return $this->scope->variables->resolvedTypes[$cacheKey] = $this->resolve();
        }

        $resolvedType = new VariableEventReplayer($this->scope, $readPath)->replay($events, $this->readBranchPath);

        if (! $resolvedType) {
            $resolvedType = new UnknownType;
        }

        $resolvedType->addDescription($this->description);
        $resolvedType->examples = $this->examples ?: $resolvedType->examples;
        $resolvedType->required = $this->required ?: $resolvedType->required;
        $resolvedType->deprecated = $resolvedType->deprecated || $this->deprecated;
        $resolvedType->addDeprecatedDescription($this->deprecatedDescription);

        $this->scope->variables->resolvedTypes[$cacheKey] = $resolvedType;

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
}
