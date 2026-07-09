<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;


class UnresolvedArrayDimType extends UnresolvedType
{
    public function __construct(
        public Type $potentialArrayType,
        public int|string $key,
        public Scope $scope,
    ) {}


    public function resolve(): Type
    {
        $type = $this->scope->withPartialArraysResolvingAsShapes(
            fn () => $this->potentialArrayType->unwrapType($this->scope->config)->unwrapType($this->scope->config),
        );

        if ($type instanceof UnionType) {
            $memberTypes = [];

            foreach ($type->types as $memberType) {
                $memberTypes[] = $this->resolveMemberAtKey($memberType);
            }

            return new UnionType($memberTypes)->unwrapType($this->scope->config);
        }

        return $this->resolveMemberAtKey($type);
    }


    private function resolveMemberAtKey(Type $type): Type
    {
        if ($type instanceof ObjectType && $type->typeToDisplay) {
            $type = $type->typeToDisplay;
        }

        if ($type instanceof ArrayType) {
            $memberType = $type->shape[$this->key] ?? $type->itemType;

        } else if ($type instanceof ObjectType) {
            $memberType = $type->properties[$this->key] ?? null;

        } else {
            $memberType = null;
        }

        return $memberType?->unwrapType($this->scope->config) ?? new UnknownType;
    }
}
