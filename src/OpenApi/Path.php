<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

use JsonSerializable;

/**
 * https://spec.openapis.org/oas/v3.1.0.html#path-item-object
 */
class Path implements JsonSerializable
{
    /**
     * @var array<string, Operation>
     */
    public array $operations = [];


    public function jsonSerialize(): mixed
    {
        return $this->operations;
    }
}
