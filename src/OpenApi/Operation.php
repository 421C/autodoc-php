<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#operation-object
 */
class Operation
{
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public ?RequestBody $requestBody = null,

        /** @var Parameter[] */
        public array $parameters = [],

        /** @var mixed[] */
        public array $responses = [],
    ) {}
}
