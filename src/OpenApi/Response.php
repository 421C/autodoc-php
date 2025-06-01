<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#response-object
 */
class Response implements JsonSerializable
{
    public function __construct(
        /** @var ?array<string, MediaType> */
        public ?array $content = null,
        public string $description = '',

        /** @var ?array<string, HeaderObject|ReferenceObject> */
        public ?array $headers = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return array_filter([
            'description' => $this->description,
            'content' => $this->content,
            'headers' => $this->headers,
        ], fn ($value) => $value !== null);
    }
}
