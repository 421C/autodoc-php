<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#operation-object
 */
class Operation implements JsonSerializable
{
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $operationId = null,
        public ?RequestBody $requestBody = null,
        public ?ExternalDocumentationObject $externalDocs = null,
        public ?bool $deprecated = null,

        /** @var ?array<int|string, Response|ReferenceObject> */
        public ?array $responses = null,

        /** @var ?list<Parameter|ReferenceObject> */
        public ?array $parameters = null,

        /** @var ?list<Server> */
        public ?array $servers = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return array_filter([
            'summary' => $this->summary,
            'description' => $this->description,
            'operationId' => $this->operationId,
            'requestBody' => $this->requestBody,
            'responses' => $this->responses,
            'externalDocs' => $this->externalDocs,
            'deprecated' => $this->deprecated,
            'servers' => $this->servers,
            'parameters' => $this->parameters,
        ], fn ($value) => $value !== null);
    }
}
