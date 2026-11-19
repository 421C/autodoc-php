<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class CachedSpecialist implements CacheableRecord
{
    use HasRecordConnector;

    public ?string $icon = null;
}
