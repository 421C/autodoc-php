<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use Exception;

/**
 * Without the generic arguments PHPStan wants, so reflection
 * has every built-in keyword to resolve.
 */
final class BuiltInKeywordHolder
{
    // @phpstan-ignore missingType.iterableValue, missingType.iterableValue
    public function parameters(
        int $int,
        float $float,
        string $string,
        bool $bool,
        true $true,
        false $false,
        array $array,
        iterable $iterable,
        object $object,
        callable $callable,
        null $null,
    ): void {}

    public function returnsVoid(): void {}

    public function returnsNever(): never
    {
        throw new Exception;
    }
}
