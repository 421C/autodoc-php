<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @template T
 */
class UnboundRecordConnector
{
    /**
     * @param class-string<T> $className
     */
    public function __construct(
        public string $className,
    ) {}

    /**
     * @return T|null
     */
    public function find(): mixed
    {
        return null;
    }
}
