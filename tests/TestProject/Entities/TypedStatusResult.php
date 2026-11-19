<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class TypedStatusResult
{
    public ?bool $eligible = null;

    public ?string $checkedAt = null;

    /**
     * @var ?int
     */
    public $count = null;

    public mixed $payload = null;

    public ?TypedStatusResult $previous = null;
}
