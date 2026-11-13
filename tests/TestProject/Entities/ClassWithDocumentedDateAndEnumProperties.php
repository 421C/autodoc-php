<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use DateTimeImmutable;

class ClassWithDocumentedDateAndEnumProperties
{
    /**
     * Plain date.
     *
     * @example 2024-01-01T00:00:00Z
     */
    public DateTimeImmutable $date;

    /**
     * Nullable date.
     *
     * @example 2024-01-01T00:00:00Z
     */
    public ?DateTimeImmutable $nullableDate;

    /** Plain enum. */
    public StateEnum $state;

    /** Nullable enum. */
    public ?StateEnum $nullableState;
}
