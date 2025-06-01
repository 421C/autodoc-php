<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#encoding-object
 */
class EncodingObject
{
    public function __construct(
        public string $contentType,

        /**
         * @var array<string, HeaderObject|ReferenceObject>
         */
        public array $headers,
        public string $style,
        public bool $explode,
        public bool $allowReserved,
    ) {}
}
