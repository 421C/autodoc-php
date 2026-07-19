<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final class NotInstanceOf extends Narrowing
{
    public function __construct(
        /**
         * @var class-string
         */
        private readonly string $className,
    ) {}

    public function apply(Type $base, Scope $scope): Type
    {
        if ($base instanceof UnionType) {
            $remaining = array_values(array_filter(
                $base->types,
                fn (Type $type) => ! $this->isInstanceOfClass($type),
            ));

            if ($remaining !== [] && count($remaining) !== count($base->types)) {
                return new UnionType($remaining)->unwrapType($scope->config);
            }
        }

        return $base;
    }

    private function isInstanceOfClass(Type $type): bool
    {
        return ($type instanceof ObjectType || $type instanceof ArrayType)
            && $type->className !== null
            && is_a($type->className, $this->className, true);
    }
}
