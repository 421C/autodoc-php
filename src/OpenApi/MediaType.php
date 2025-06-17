<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use AutoDoc\DataTypes\Type;
use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#media-type-object
 */
class MediaType implements JsonSerializable
{
    public function __construct(
        /**
         * @var ?array<string, mixed>
         */
        public ?array $schema = null,

        /**
         * @var ?array<string, EncodingObject>
         */
        public ?array $encoding = null,

        /**
         * @var ?array<string, ExampleObject|ReferenceObject>
         */
        public ?array $examples = null,
        public mixed $example = null,
        public ?Type $type = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return array_filter([
            'schema' => $this->schema,
            'encoding' => $this->encoding,
            'examples' => $this->examples,
            'example' => $this->example,
        ], fn ($value) => $value !== null);
    }
}
