<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedClassType;

final class IsInstanceOf extends Narrowing
{
    public function __construct(
        /**
         * @var class-string
         */
        private readonly string $className,
    ) {}

    public function apply(Type $base, Scope $scope): Type
    {
        return new IsType(new UnresolvedClassType($this->className, $scope))->apply($base, $scope);
    }
}
