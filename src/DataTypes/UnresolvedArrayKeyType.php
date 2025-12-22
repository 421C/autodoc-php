<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;


class UnresolvedArrayKeyType extends UnresolvedType
{
    public function __construct(
        public Type $potentialArrayType,
        public Scope $scope,
    ) {}


    public function resolve(): Type
    {
        $type = $this->potentialArrayType->unwrapType($this->scope->config);

        if ($type instanceof ArrayType) {
            return $type->convertShapeToTypePair()->keyType ?? new UnknownType;

        } else if ($type instanceof ObjectType) {
            return new StringType;

        }

        return new UnknownType;
    }
}
