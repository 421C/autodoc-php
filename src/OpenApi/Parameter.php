<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#parameter-object
 */
class Parameter
{
    public function __construct(
        public string $name,
        public string $in,
        public string $description,
        public bool $required,
        public bool $deprecated,
        public bool $allowEmptyValue,
    ) {}
}
