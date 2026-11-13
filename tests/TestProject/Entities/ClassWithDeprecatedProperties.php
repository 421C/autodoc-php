<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use DateTimeImmutable;

class ClassWithDeprecatedProperties
{
    /** @deprecated Use `slug`. */
    public string $plain;

    /** @deprecated Use `slug`. */
    public ?string $nullable;

    /** @deprecated Use `launchedAt`. */
    public DateTimeImmutable $date;

    /** @deprecated Use `launchedAt`. */
    public ?DateTimeImmutable $nullableDate;
}
