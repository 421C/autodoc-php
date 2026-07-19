<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

/**
 * Base for scalar types that can carry one or more literal values
 * (`StringType`, `IntegerType`, `FloatType`, `NumberType`).
 *
 * @phpstan-import-type TypeSchema from Type
 */
abstract class ScalarType extends Type
{
    /**
     * The literal value(s) this scalar is constrained to, or `null` when unconstrained.
     *
     * @return list<float|int|string>|null
     */
    abstract public function getPossibleValues(): ?array;

    /**
     * Replace the literal value(s) this scalar carries.
     *
     * @param list<float|int|string> $values
     */
    abstract public function setPossibleValues(array $values): void;

    /**
     * Whether values can be emitted as JSON/OpenAPI/TypeScript literals.
     * Non-finite floats prevent `const`/`enum` output.
     *
     * @param list<float|int|string> $values
     */
    public static function canRepresentLiteralValues(array $values): bool
    {
        return array_all($values, fn ($value) => !is_float($value) || is_finite($value));
    }

    /**
     * Append `const`/`enum` to a schema when literal values are present and either
     * this is an enum or the config opts into showing values for scalar types.
     *
     * @param TypeSchema $schema
     *
     * @return TypeSchema
     */
    protected function withScalarValues(array $schema, Config $config): array
    {
        if (! $this->isEnum && ! ($config->data['openapi']['show_values_for_scalar_types'] ?? false)) {
            return $schema;
        }

        $possibleValues = $this->getPossibleValues();

        if ($possibleValues && self::canRepresentLiteralValues($possibleValues)) {
            if (count($possibleValues) === 1) {
                $schema['const'] = $possibleValues[0];

            } else {
                $schema['enum'] = $possibleValues;
            }
        }

        return $schema;
    }
}
