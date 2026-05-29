<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final class AnyOf extends Narrowing
{
    /**
     * @param list<Narrowing> $narrowings
     */
    public function __construct(
        private readonly array $narrowings,
    ) {}

    public function apply(Type $base, Scope $scope): Type
    {
        $types = [];

        foreach ($this->narrowings as $narrowing) {
            $types[] = $narrowing->apply($base, $scope);
        }

        return new UnionType($types)->unwrapType($scope->config);
    }
}
