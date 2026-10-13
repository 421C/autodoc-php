<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class NumberType extends ScalarType
{
    public function __construct(
        /**
         * @var int|float|array<int|float>|null
         */
        public int|float|array|null $value = null,
        public ?string $description = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public bool $isString = false,
    ) {}


    /**
     * @return list<int|float>|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_array($this->value) ? array_values($this->value) : [$this->value];
    }


    public function setPossibleValues(array $values): void
    {
        /** @var list<int|float> $values */
        $this->value = count($values) === 1 ? $values[0] : $values;
    }


    public function toSchema(Config $config): array
    {
        $schema = array_filter([
            'type' => 'number',
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
            $schema['type'] = 'string';

            if ($config->data['openapi']['use_pattern_for_numeric_strings'] ?? false) {
                $schema['pattern'] = '^[+-]?[0-9]+(\.[0-9]+)?$';

            } else {
                $schema['format'] = 'numeric';
            }
        }

        return $schema;
    }
}
