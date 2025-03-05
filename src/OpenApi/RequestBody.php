<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#request-body-object
 *
 * @property array<string, MediaType> $content
 */
class RequestBody
{
    public function __construct(
        /**
         * @var array<string, MediaType>
         */
        public array $content,
        public string $description = '',
        public bool $required = false,
    ) {}
}
