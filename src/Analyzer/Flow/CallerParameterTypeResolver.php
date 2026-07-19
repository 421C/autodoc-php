<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Narrowing\TypeNarrowingApplier;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;

final readonly class CallerParameterTypeResolver
{
    public function __construct(
        private Scope $scope,
        private CallerParameterBindingAnalysis $bindingAnalysis,
    ) {}

    public function resolveAtExit(int $readFilePos, BranchPath $readBranchPath): Type
    {
        $originalType = $this->bindingAnalysis->getOriginalBindingType();

        if ($originalType === null) {
            return new UnknownType;
        }

        $resolvedType = $originalType->unwrapType($this->scope->config);
        $mutationApplier = new AttributeMutationApplier($this->scope);
        $narrowingApplier = new TypeNarrowingApplier($this->scope);

        foreach ($this->bindingAnalysis->getEventsAffectingOriginalBindingAtExit(
            readFilePos: $readFilePos,
            readBranchPath: $readBranchPath,
        ) as $originalBindingEvent) {
            $event = $originalBindingEvent['event'];
            $isCertain = $originalBindingEvent['visibility'] === ScopeEventVisibility::Certain;

            if ($event->type === ScopeEventType::Mutate
                && (! empty($event->changes['attributes']) || isset($event->changes['dynamicAttribute']))
            ) {
                $this->scope->withShapeMerging(function () use ($event, &$resolvedType, $isCertain, $mutationApplier) {
                    $resolvedType = $mutationApplier->apply(
                        baseType: $resolvedType,
                        mutationPath: $event->changes['mutationPath'] ?? [],
                        attributes: $event->changes['attributes'] ?? [],
                        isCertain: $isCertain,
                        dynamicAttribute: $event->changes['dynamicAttribute'] ?? null,
                    ) ?? $resolvedType;
                });

            } else if ($isCertain
                && $event->type === ScopeEventType::Narrow
                && isset($event->changes['narrowing'])
            ) {
                $resolvedType = $narrowingApplier->applyPath(
                    base: $resolvedType,
                    path: $event->changes['narrowingPath'] ?? [],
                    narrowing: $event->changes['narrowing'],
                );
            }
        }

        return $resolvedType;
    }
}
