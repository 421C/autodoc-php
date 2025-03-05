<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#info-object
 */
class InfoObject
{
    public function __construct(
        public string $title,
        public string $version,
        public string $description,
    ) {}
}
