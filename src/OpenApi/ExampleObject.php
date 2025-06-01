<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#example-object
 */
class ExampleObject
{
    public function __construct(
        public string $summary,
        public string $description,
        public mixed $value,
        public ?string $externalValue,
    ) {}
}
