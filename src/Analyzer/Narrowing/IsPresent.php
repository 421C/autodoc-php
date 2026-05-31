<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

/**
 * Asserts that an attribute path is present — e.g. the true side of
 * `isset($arr['key'])` — so the shape key it targets is marked required.
 * Applied to a plain variable it only sets the (unused) top-level required flag.
 */
final class IsPresent extends Narrowing
{
    public function apply(Type $base, Scope $scope): Type
    {
        $base->required = true;

        return $base;
    }

    public function assertsPresence(): bool
    {
        return true;
    }
}
