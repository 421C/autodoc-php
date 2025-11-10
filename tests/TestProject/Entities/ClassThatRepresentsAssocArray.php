<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use JsonSerializable;

/**
 * @template T
 */
class ClassThatRepresentsAssocArray implements JsonSerializable
{
    public function __construct(
        /** @var array<string, T> */
        public array $data,
    ) {}

    /**
     * @return array<string, T>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
