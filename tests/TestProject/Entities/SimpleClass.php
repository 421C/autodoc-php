<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class SimpleClass
{
    public function __construct(
        public ?int $n = null,
    ) {}

    public function getValue(): ?int
    {
        return $this->n;
    }
}
