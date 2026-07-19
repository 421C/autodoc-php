<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing\Traits;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\ScalarType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

trait FiltersByTruthiness
{
    private function filterByTruthiness(Type $base, Scope $scope, bool $truthy): Type
    {
        $base = $base->unwrapType($scope->config);

        if ($base instanceof UnionType) {
            return new UnionType(array_map(
                fn (Type $type): Type => $this->filterByTruthiness($type, $scope, $truthy),
                $base->types,
            ))->unwrapType($scope->config);
        }

        if ($base instanceof ScalarType) {
            return $this->filterScalarValuesByTruthiness($base, $truthy);
        }

        if ($base instanceof BoolType) {
            return $this->filterBoolValuesByTruthiness($base, $truthy);
        }

        if ($base instanceof NullType) {
            return $truthy ? new NeverType : $base;
        }

        if ($base instanceof ObjectType) {
            return $truthy ? $base : new NeverType;
        }

        if ($base instanceof ArrayType) {
            if (array_any($base->shape, fn (Type $type): bool => $type->required)
                || ($base->minItems !== null && $base->minItems > 0)
            ) {
                return $truthy ? $base : new NeverType;
            }

            if ($base->maxItems === 0) {
                return $truthy ? new NeverType : $base;
            }
        }

        return $base;
    }

    private function filterScalarValuesByTruthiness(ScalarType $base, bool $truthy): Type
    {
        $values = $base->getPossibleValues();

        if ($values === null) {
            return $base;
        }

        $remaining = array_values(array_filter(
            $values,
            fn (float|int|string $value): bool => (bool) $value === $truthy,
        ));

        if ($remaining === []) {
            return new NeverType;
        }

        $base = clone $base;
        $base->setPossibleValues($remaining);

        return $base;
    }

    private function filterBoolValuesByTruthiness(BoolType $base, bool $truthy): Type
    {
        $values = $base->value === null ? [false, true] : [$base->value];

        $remaining = array_values(array_filter(
            $values,
            fn (bool $value): bool => $value === $truthy,
        ));

        if ($remaining === []) {
            return new NeverType;
        }

        return new BoolType($remaining[0]);
    }
}
