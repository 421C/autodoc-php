<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Support;

const DEFAULT_STATUS = 'pending';
const MAX_ATTEMPTS = 3;

function makeStatus(): string
{
    return 'ok';
}

function makeCount(): int
{
    return 42;
}
