<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#external-documentation-object
 */
class ExternalDocumentationObject
{
    public function __construct(
        public string $description,
        public string $url,
    ) {}
}
