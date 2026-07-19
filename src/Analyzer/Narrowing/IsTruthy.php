<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Narrowing\Traits\FiltersByTruthiness;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

final class IsTruthy extends Narrowing
{
    use FiltersByTruthiness;

    public function apply(Type $base, Scope $scope): Type
    {
        return $this->filterByTruthiness($base, $scope, truthy: true);
    }
}
