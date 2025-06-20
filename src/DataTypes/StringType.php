<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class StringType extends Type
{
    public function __construct(
        /**
         * @var string|string[]|null
         */
        public string|array|null $value = null,
        public ?string $description = null,
        public ?string $format = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
    ) {}


    /**
     * @return string[]|null
     */
    public function getPossibleValues(): ?array
    {
        if (! isset($this->value)) {
            return null;
        }

        return is_string($this->value) ? [$this->value] : $this->value;
    }


    public function toSchema(?Config $config = null): array
    {
        $schema = array_filter([
            'type' => 'string',
            'format' => $this->format,
            'description' => $this->description,
            'examples' => $this->examples,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
        ]);

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

        return $schema;
    }
}
