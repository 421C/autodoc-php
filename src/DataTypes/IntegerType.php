<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

class IntegerType extends Type
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


    /**
     * @return int[]|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_int($this->value) ? [$this->value] : $this->value;
    }


    public function toSchema(): array
    {
        $schema = array_filter([
            'type' => 'integer',
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
