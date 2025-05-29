<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class NumberType extends Type
{
    public function __construct(
        /**
         * @var int|float|array<int|float>|null
         */
        public int|float|array|null $value = null,
        public ?string $description = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public bool $isString = false,
    ) {}


    /**
     * @return array<int|float>|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_array($this->value) ? $this->value : [$this->value];
    }


    public function toSchema(?Config $config = null): array
    {
        $schema = array_filter([
            'type' => 'number',
            'description' => $this->description,
            'examples' => $this->examples,
        ]);

        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }

        if ($this->maximum !== null) {
            $schema['maximum'] = $this->maximum;
        }

        if ($this->isEnum || ($config?->data['openapi']['show_values_for_scalar_types'] ?? false)) {
            $possibleValues = $this->getPossibleValues();

            if ($possibleValues) {
                if (count($possibleValues) === 1) {
                    $schema['const'] = $possibleValues[0];

                } else {
                    $schema['enum'] = $possibleValues;
                }
            }
        }

        if ($this->isString) {
            // OpenApi 3.1.0 string type does not support `minimum` and `maximum` properties,
            // so we only set the type to string if these properties are not set.
            if ($this->minimum === null && $this->maximum === null) {
                $schema['type'] = 'string';

                if ($config?->data['openapi']['use_pattern_for_numeric_strings'] ?? false) {
                    $schema['pattern'] = '^[+-]?[0-9]+(\.[0-9]+)?$';

                } else {
                    $schema['format'] = 'numeric';
                }
            }
        }

        return $schema;
    }
}
