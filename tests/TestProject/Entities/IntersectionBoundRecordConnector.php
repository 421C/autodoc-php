<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @template TRecord of (object & CacheableRecord)
 */
class IntersectionBoundRecordConnector
{
    /**
     * @param class-string<TRecord> $className
     */
    public function __construct(
        public string $className,
    ) {}

    /**
     * @return TRecord|null
     */
    public function find(): ?object
    {
        return null;
    }
}
