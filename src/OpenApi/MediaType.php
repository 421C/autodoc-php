<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#media-type-object
 */
class MediaType
{
    public function __construct(
        /**
         * @var array<string, mixed>
         */
        public array $schema,
    ) {}
}
