<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#server-object
 */
class Server
{
    public function __construct(
        public string $url,
        public string $description,
    ) {}
}
