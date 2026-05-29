<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

abstract class Narrowing
{
    abstract public function apply(Type $base, Scope $scope): Type;
}
