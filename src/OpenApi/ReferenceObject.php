<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#reference-object
 */
class ReferenceObject implements JsonSerializable
{
    public function __construct(
        public string $ref,
        public ?string $summary,
        public ?string $description,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            '$ref' => $this->ref,
            'summary' => $this->summary,
            'description' => $this->description,
        ];
    }
}
