<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Narrowing\Traits\FiltersLooseScalarValues;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\ScalarType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final class NotType extends Narrowing
{
    use FiltersLooseScalarValues;

    public function __construct(
        private readonly Type $type,
        private readonly bool $strict = true,
    ) {}

    public function apply(Type $base, Scope $scope): Type
    {
        $excludedType = $this->type->unwrapType($scope->config);

        if (! $this->strict) {
            $excludedValues = $this->looseComparableValues($excludedType);

            if ($excludedValues === null) {
                return $base;
            }

            return $this->filterLooseScalarValues($base, $excludedValues, keepMatching: false, scope: $scope);
        }

        if ($this->hasSpecificValues($excludedType)) {
            return $this->removeSpecificTypeOrNull($base, $excludedType, $scope) ?? $base;
        }

        if ($base instanceof UnionType) {
            $remaining = array_values(array_filter(
                $base->types,
                fn (Type $type) => $type::class !== $excludedType::class,
            ));

            if ($remaining !== [] && count($remaining) !== count($base->types)) {
                return new UnionType($remaining)->unwrapType($scope->config);
            }
        }

        return $base;
    }

    private function removeSpecificTypeOrNull(Type $base, Type $excludedType, Scope $scope): ?Type
    {
        if ($base instanceof UnionType) {
            $remaining = [];
            $changed = false;

            foreach ($base->types as $type) {
                $remainingType = $this->removeSpecificTypeOrNull($type, $excludedType, $scope);

                if ($remainingType === null) {
                    $changed = true;

                    continue;
                }

                if ($remainingType !== $type) {
                    $changed = true;
                }

                $remaining[] = $remainingType;
            }

            if ($remaining === []) {
                return null;
            }

            if (! $changed) {
                return $base;
            }

            return new UnionType($remaining)->unwrapType($scope->config);
        }

        return $this->removeSpecificScalarType($base, $excludedType);
    }

    private function removeSpecificScalarType(Type $base, Type $excludedType): ?Type
    {
        if ($base instanceof BoolType && $excludedType instanceof BoolType) {
            return $this->removeValueFromBoolType($base, $excludedType);
        }

        if (! $base instanceof ScalarType
            || ! $excludedType instanceof ScalarType
            || $base::class !== $excludedType::class
        ) {
            return $base;
        }

        return $this->removeValuesFromScalarType($base, $excludedType);
    }

    private function hasSpecificValues(Type $type): bool
    {
        if ($type instanceof ScalarType) {
            return $type->getPossibleValues() !== null;
        }

        if ($type instanceof BoolType) {
            return $type->value !== null;
        }

        return false;
    }

    private function removeValuesFromScalarType(ScalarType $base, ScalarType $excludedType): ?ScalarType
    {
        $excludedValues = $excludedType->getPossibleValues();
        $baseValues = $base->getPossibleValues();

        if ($excludedValues === null || $baseValues === null) {
            return $base;
        }

        $remainingValues = array_values(array_filter(
            $baseValues,
            fn (float|int|string $value) => ! in_array($value, $excludedValues, true),
        ));

        if (count($remainingValues) === count($baseValues)) {
            return $base;
        }

        if ($remainingValues === []) {
            return null;
        }

        $type = clone $base;
        $type->setPossibleValues($remainingValues);

        return $type;
    }

    private function removeValueFromBoolType(BoolType $base, BoolType $excludedType): ?BoolType
    {
        if ($excludedType->value === null) {
            return $base;
        }

        if ($base->value === null) {
            return new BoolType(! $excludedType->value);
        }

        if ($base->value === $excludedType->value) {
            return null;
        }

        return $base;
    }
}
