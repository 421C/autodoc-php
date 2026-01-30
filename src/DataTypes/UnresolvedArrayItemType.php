<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;


class UnresolvedArrayItemType extends UnresolvedType
{
    public function __construct(
        public Type $potentialArrayType,
        public Scope $scope,
    ) {}


    public function resolve(): Type
    {
        $type = $this->potentialArrayType->unwrapType($this->scope->config)->unwrapType($this->scope->config);

        if ($type instanceof ArrayType) {
            return $type->convertShapeToTypePair()->itemType ?? new UnknownType;

        } else if ($type instanceof ObjectType) {
            return (new UnionType(array_values($type->properties)))->unwrapType($this->scope->config);

        } else if ($type instanceof StringType) {
            return new StringType;
        }

        return new UnknownType;
    }
}
