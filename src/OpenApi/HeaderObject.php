<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#header-object
 */
class HeaderObject
{
    public function __construct(
        public string $description,
        public bool $required,
        public bool $deprecated,
        public string $style,
        public bool $allowEmptyValue,
        public bool $allowReserved,

        /**
         * @var array<string, mixed>
         */
        public array $schema,

        /**
         * @var array<string, MediaType>
         */
        public array $content,

        /**
         * @var array<string, ExampleObject>
         */
        public array $examples,
        public mixed $example,
    ) {}
}
