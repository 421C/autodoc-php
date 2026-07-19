<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

final class NotNull extends Narrowing
{
    public function apply(Type $base, Scope $scope): Type
    {
        return $base->removeNull($scope->config);
    }
}
