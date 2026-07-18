<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Extensions\FuncCallContext;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\FuncCallExtension;

/**
 * Counts how many times each function call is resolved, to assert that
 * breakout detection does not re-resolve the same callee redundantly.
 */
class FuncCallCountingExtension extends FuncCallExtension
{
    /**
     * @var array<string, int>
     */
    public static array $callCounts = [];

    public static function reset(): void
    {
        self::$callCounts = [];
    }

    public function getReturnType(FuncCallContext $context): ?Type
    {
        if ($context->functionName !== null) {
            self::$callCounts[$context->functionName] = (self::$callCounts[$context->functionName] ?? 0) + 1;
        }

        return null;
    }
}
