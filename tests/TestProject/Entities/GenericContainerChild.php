<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @template T
 *
 * @extends GenericContainer<T>
 */
class GenericContainerChild extends GenericContainer
{
    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        parent::__construct($value);
    }
}
