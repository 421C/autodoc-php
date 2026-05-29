<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class StringType extends ScalarType
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
     * @return list<string>|null
     */
    public function getPossibleValues(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return is_string($this->value) ? [$this->value] : array_values($this->value);
    }


    public function setPossibleValues(array $values): void
    {
        /** @var list<string> $values */
        $this->value = count($values) === 1 ? $values[0] : $values;
    }


    public function toSchema(?Config $config = null): array
    {
        $schema = array_filter([
            'type' => 'string',
            'format' => $this->format,
            'description' => $this->description,
            'examples' => $this->examples ? array_values($this->examples) : null,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'deprecated' => $this->deprecated,
            'x-deprecated-description' => $this->deprecatedDescription,
        ]);

        return $this->withScalarValues($schema, $config);
    }
}
