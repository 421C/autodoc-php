<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use DateTimeImmutable;

class Rocket
{
    public int $id;
    public string $name;
    public RocketCategory $category;
    public ?DateTimeImmutable $launch_date;

    /**
     * @deprecated
     */
    public bool $is_flying;
}
