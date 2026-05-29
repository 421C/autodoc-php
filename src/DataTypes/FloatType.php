<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class FloatType extends ScalarType
{
    public function __construct(
        /**
         * @var float|float[]|null
         */
        public float|array|null $value = null,
        public ?string $description = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
    ) {}


    /**
     * @return list<float>|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_float($this->value) ? [$this->value] : array_values($this->value);
    }


    public function setPossibleValues(array $values): void
    {
        /** @var list<float> $values */
        $this->value = count($values) === 1 ? $values[0] : $values;
    }


    public function toSchema(?Config $config = null): array
    {
        $schema = array_filter([
            'type' => 'number',
            'format' => 'float',
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

        return $this->withScalarValues($schema, $config);
    }
}
