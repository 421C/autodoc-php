<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

abstract class Narrowing
{
    abstract public function apply(Type $base, Scope $scope): Type;

    /**
     * Whether this narrowing asserts the target attribute is present, so a shape
     * key it targets becomes required rather than keeping its prior optionality.
     */
    public function assertsPresence(): bool
    {
        return false;
    }
}
