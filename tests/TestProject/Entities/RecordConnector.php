<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @template T of CacheableRecord
 */
class RecordConnector
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
    public function find(): ?object
    {
        return null;
    }
}
