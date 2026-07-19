<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing\Traits;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ScalarType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

/**
 * Shared loose (`==`) value-set filtering used by IsType / NotType when they
 * narrow by PHP's loose comparison rules rather than strict identity.
 */
trait FiltersLooseScalarValues
{
    /**
     * The values a type can be loosely compared against, or null when it carries
     * no comparable values (an open or non-scalar type, which can't be narrowed
     * by value).
     *
     * @return list<bool|float|int|string|null>|null
     */
    private function looseComparableValues(Type $type): ?array
    {
        if ($type instanceof ScalarType) {
            return $type->getPossibleValues();
        }

        if ($type instanceof BoolType) {
            return $type->value === null ? null : [$type->value];
        }

        if ($type instanceof NullType) {
            return [null];
        }

        return null;
    }

    /**
     * Narrow $base to the scalar/bool values that loosely match (when
     * $keepMatching) or do not match (otherwise) any of $candidateValues,
     * recursing through unions. A scalar/bool whose values are all rejected
     * collapses to `never`; an open scalar or non-scalar type is returned
     * unchanged, since it can't be narrowed by value.
     *
     * @param list<bool|float|int|string|null> $candidateValues
     */
    private function filterLooseScalarValues(Type $base, array $candidateValues, bool $keepMatching, Scope $scope): Type
    {
        if ($base instanceof UnionType) {
            return new UnionType(array_map(
                fn (Type $type): Type => $this->filterLooseScalarValues($type->unwrapType($scope->config), $candidateValues, $keepMatching, $scope),
                $base->types,
            ))->unwrapType($scope->config);
        }

        if ($base instanceof ScalarType) {
            $values = $base->getPossibleValues();

            if ($values === null) {
                return $base;
            }

            $remaining = array_values(array_filter(
                $values,
                fn (float|int|string $value): bool => in_array($value, $candidateValues, false) === $keepMatching,
            ));

            if ($remaining === []) {
                return new NeverType;
            }

            $base = clone $base;
            $base->setPossibleValues($remaining);

            return $base;
        }

        if ($base instanceof BoolType) {
            $values = $base->value === null ? [false, true] : [$base->value];

            $remaining = array_values(array_filter(
                $values,
                fn (bool $value): bool => in_array($value, $candidateValues, false) === $keepMatching,
            ));

            if ($remaining === []) {
                return new NeverType;
            }

            return count($remaining) === 1 ? new BoolType($remaining[0]) : new BoolType;
        }

        if ($base instanceof NullType) {
            return in_array(null, $candidateValues, false) === $keepMatching
                ? $base
                : new NeverType;
        }

        return $base;
    }
}
