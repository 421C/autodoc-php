<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

final class AllOf extends Narrowing
{
    /**
     * @param list<Narrowing> $narrowings
     */
    public function __construct(
        private readonly array $narrowings,
    ) {}

    public function apply(Type $base, Scope $scope): Type
    {
        foreach ($this->narrowings as $narrowing) {
            $base = $narrowing->apply($base, $scope);
        }

        return $base;
    }
}
