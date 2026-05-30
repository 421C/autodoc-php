<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Narrowing\Traits\FiltersLooseScalarValues;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final class IsType extends Narrowing
{
    use FiltersLooseScalarValues;

    public function __construct(
        private readonly Type $type,
        private readonly bool $strict = true,
    ) {}

    public function apply(Type $base, Scope $scope): Type
    {
        $narrowedType = $this->type->unwrapType($scope->config);

        if (! $this->strict) {
            $candidateValues = $this->looseComparableValues($narrowedType);

            if ($candidateValues === null) {
                return $base;
            }

            return $this->filterLooseScalarValues($base, $candidateValues, keepMatching: true, scope: $scope);
        }

        if ($base instanceof UnionType) {
            $matchingTypes = [];

            foreach ($base->types as $type) {
                $matchingType = $this->intersectType($type, $narrowedType, $scope);

                if ($matchingType !== null) {
                    $matchingTypes[] = $matchingType;
                }
            }

            if ($matchingTypes !== []) {
                return new UnionType($matchingTypes)->unwrapType($scope->config);
            }

            // No union member is compatible with the narrowed type, so the
            // condition is a contradiction and this branch is never reached.
            return new NeverType;
        }

        $intersectedType = $this->intersectType($base, $narrowedType, $scope);

        if ($intersectedType !== null) {
            return $intersectedType;
        }

        return $narrowedType;
    }

    private function intersectType(Type $base, Type $narrowedType, Scope $scope): ?Type
    {
        if ($base->isSubTypeOf($narrowedType)) {
            return $base;
        }

        if ($narrowedType->isSubTypeOf($base)) {
            return $narrowedType;
        }

        $intersectedType = new IntersectionType([$base, $narrowedType])->unwrapType($scope->config);

        if ($intersectedType instanceof IntersectionType && count($intersectedType->types) === 1) {
            $intersectedType = $intersectedType->unwrapType($scope->config);
        }

        return $intersectedType instanceof IntersectionType ? null : $intersectedType;
    }
}
