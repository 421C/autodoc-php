<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class IntegerType extends ScalarType
{
    public function __construct(
        /**
         * @var int|int[]|null
         */
        public int|array|null $value = null,
        public ?string $description = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
    ) {}

    public bool $isString = false;
    public bool $isStrictInteger = false;

    /**
     * Allow `true` to be validated as integer.
     *
     * Laravel validation uses `filter_var(..., FILTER_VALIDATE_INT)`
     * to validate integer values, which returns `1` for `true`.
     */
    public bool $allowTrueAsInteger = false;

    /**
     * @return list<int>|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_int($this->value) ? [$this->value] : array_values($this->value);
    }


    public function setPossibleValues(array $values): void
    {
        /** @var list<int> $values */
        $this->value = count($values) === 1 ? $values[0] : $values;
    }


    public function toSchema(?Config $config = null): array
    {
        $schema = array_filter([
            'type' => 'integer',
            'description' => $this->description,
            'examples' => $this->examples ? array_values($this->examples) : null,
            'deprecated' => $this->deprecated,
            'x-deprecated-description' => $this->deprecatedDescription,
        ]);

        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }

        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }

        $schema = $this->withScalarValues($schema, $config);

        if ($this->isString) {
            // OpenApi 3.1.0 string type does not support `minimum` and `maximum` properties,
            // so we only set the type to string if these properties are not set.
            if ($this->minimum === null && $this->maximum === null) {
                $schema['type'] = 'string';

                if ($config?->data['openapi']['use_pattern_for_numeric_strings'] ?? false) {
                    $schema['pattern'] = '^[0-9]+$';

                } else {
                    $schema['format'] = 'integer';
                }
            }
        }

        return $schema;
    }
}
