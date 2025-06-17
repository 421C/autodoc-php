<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#parameter-object
 */
class Parameter implements JsonSerializable
{
    public function __construct(
        public string $name,

        /**
         * @var 'path' | 'query' | 'header' | 'cookie'
         */
        public string $in,
        public ?string $description = null,
        public ?bool $required = null,
        public ?bool $deprecated = null,
        public ?string $style = null,
        public ?bool $allowEmptyValue = null,
        public ?bool $allowReserved = null,

        /**
         * @var ?array<string, mixed>
         */
        public ?array $schema = null,

        /**
         * @var ?array<string, MediaType>
         */
        public ?array $content = null,

        /**
         * @var ?array<string, ExampleObject|ReferenceObject>
         */
        public ?array $examples = null,
        public mixed $example = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return array_filter([
            'name' => $this->name,
            'in' => $this->in,
            'description' => $this->description,
            'required' => $this->required ?: null,
            'deprecated' => $this->deprecated ?: null,
            'style' => $this->style,
            'allowEmptyValue' => $this->allowEmptyValue ?: null,
            'allowReserved' => $this->allowReserved ?: null,
            'schema' => $this->schema,
            'content' => $this->content,
            'examples' => $this->examples,
            'example' => $this->example,
        ], fn ($value) => $value !== null);
    }
}
