<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Narrowing\TypeNarrowingApplier;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;

/**
 * @phpstan-import-type PathKey from ArrayType
 */
final readonly class CallerParameterTypeResolver
{
    public function __construct(
        private Scope $scope,
        private CallerParameterBindingAnalysis $bindingAnalysis,
        private bool $isPassedByReference = false,
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
        $unsetApplier = new ArrayKeyUnsetApplier($this->scope);

        foreach ($this->bindingAnalysis->getEventsAffectingOriginalBindingAtExit(
            readFilePos: $readFilePos,
            readBranchPath: $readBranchPath,
        ) as $originalBindingEvent) {
            $event = $originalBindingEvent['event'];
            $isCertain = $originalBindingEvent['visibility'] === ScopeEventVisibility::Certain;

            if ($event->type === ScopeEventType::Mutate
                && (! empty($event->changes['attributes']) || isset($event->changes['dynamicAttribute']))
                && $this->isSharedWithCaller($resolvedType, $event->changes['mutationPath'] ?? [])
            ) {
                $this->scope->withShapeMerging(function () use ($event, &$resolvedType, $isCertain, $mutationApplier) {
                    $resolvedType = $mutationApplier->apply(
                        baseType: $resolvedType,
                        mutationPath: $event->changes['mutationPath'] ?? [],
                        attributes: $event->changes['attributes'] ?? [],
                        isCertain: $isCertain,
                        dynamicAttribute: $event->changes['dynamicAttribute'] ?? null,
                        dynamicKey: $event->changes['dynamicKey'] ?? null,
                    ) ?? $resolvedType;
                });

            } else if ($event->type === ScopeEventType::Unset
                && isset($event->changes['unsetPath'])
                && $this->isSharedWithCaller($resolvedType, array_slice($event->changes['unsetPath'], 0, -1))
            ) {
                $resolvedType = $unsetApplier->apply($resolvedType, $event->changes['unsetPath'], $isCertain);

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


    /**
     * @param list<PathKey> $containerPath The keys leading to the changed array or object.
     */
    private function isSharedWithCaller(Type $parameterType, array $containerPath): bool
    {
        return $this->isPassedByReference || $this->hasObjectOnPath($parameterType, $containerPath);
    }


    /**
     * @param list<PathKey> $path
     */
    private function hasObjectOnPath(Type $type, array $path): bool
    {
        $type = $type->unwrapType($this->scope->config);

        if ($type instanceof UnionType) {
            return array_any($type->types, fn (Type $member) => $this->hasObjectOnPath($member, $path));
        }

        if ($type instanceof ObjectType
            || $type instanceof UnknownType
            || ($type instanceof ArrayType && $type->className !== null)
        ) {
            return true;
        }

        if ($path === [] || ! $type instanceof ArrayType) {
            return false;
        }

        $key = $path[0];
        $elementTypes = $key === null || $key instanceof Type
            ? [...array_values($type->shape), ...array_filter([$type->itemType])]
            : array_filter([$type->shape[$key] ?? $type->itemType]);

        return array_any($elementTypes, fn (Type $elementType) => $this->hasObjectOnPath($elementType, array_slice($path, 1)));
    }
}
