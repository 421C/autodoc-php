<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

trait HasRecordConnector
{
    /**
     * @return RecordConnector<static>
     */
    public static function connector(): RecordConnector
    {
        return new RecordConnector(static::class);
    }
}
