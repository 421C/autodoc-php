<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#schema
 */
class RootSchema
{
    public string $openapi = '3.1.0';

    public InfoObject $info;

    /** @var Server[] */
    public array $servers = [];

    /** @var array<string, Path> */
    public array $paths = [];

    /**
     * @var array{
     *     schemas?: array<string, array<string, mixed>>,
     * }
     */
    public array $components = [];
}
