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
            $typePair = (clone $type)->convertShapeToTypePair($this->scope->config);

            if ($typePair->isUntypedOrKnownEmpty()) {
                return new UnionType([
                    new IntegerType,
                    new StringType,
                ]);
            }

            return $typePair->keyType ?? new IntegerType;

        } else if ($type instanceof ObjectType) {
            return new StringType;

        }

        return new UnknownType;
    }
}
