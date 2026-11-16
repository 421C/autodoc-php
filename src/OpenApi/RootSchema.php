<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#schema
 */
class RootSchema implements JsonSerializable
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
     *     securitySchemes?: array<string, array<string, mixed>>,
     * }
     */
    public array $components = [];


    public function jsonSerialize(): mixed
    {
        $components = $this->components;

        if (isset($components['schemas'])) {
            $components['schemas'] = array_map(SchemaJson::castPropertiesToObjects(...), $components['schemas']);
        }

        return array_filter([
            'openapi' => $this->openapi,
            'info' => $this->info,
            'servers' => $this->servers,
            'paths' => (object) $this->paths,
            'components' => $components ?: null,
        ], fn ($value) => $value !== null);
    }
}
