<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @template T
 *
 * @property array<string, T> $keyed
 */
class GenericContainer
{
    /**
     * @var ?T
     */
    public mixed $nullable = null;

    /**
     * @var list<T>
     */
    public array $items = [];

    /**
     * @var array{value: T}
     */
    public array $wrapped;

    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        $this->wrapped = ['value' => $value];
    }
}
