<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

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
    ) {}


    /**
     * @return array<int|float>|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_int($this->value) || is_float($this->value) ? [$this->value] : $this->value;
    }


    public function toSchema(): array
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

        $possibleValues = $this->getPossibleValues();

        if ($possibleValues) {
            if (count($possibleValues) === 1) {
                $schema['const'] = $possibleValues[0];

            } else {
                $schema['enum'] = $possibleValues;
            }
        }

        return $schema;
    }
}
