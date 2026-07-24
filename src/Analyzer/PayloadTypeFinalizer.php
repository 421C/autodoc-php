<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final readonly class PayloadTypeFinalizer
{
    public function __construct(
        private Scope $scope,
    ) {}


    /**
     * @param list<Type> $types
     */
    public function finalizeRequestBodyTypes(array $types): ?Type
    {
        if ($types === []) {
            return null;
        }

        $type = new IntersectionType($types)->deepClone();

        return $this->scope->withShapeMerging(
            callback: fn (): Type => $this->scope->withCoerciveScalarOverlap(
                callback: fn (): Type => $type
                    ->deepResolve($this->scope->config)
                    ->unwrapType($this->scope->config),
            ),
        );
    }


    /**
     * @param list<Type> $types
     */
    public function finalizeResponseTypes(array $types): Type
    {
        return $this->finalizeResponseType(
            type: new UnionType($types)
                ->deepClone()
                ->deepResolve($this->scope->config),
            isRoot: true,
        );
    }


    private function finalizeResponseType(Type $type, bool $isRoot): Type
    {
        $type = $type->unwrapType($this->scope->config);

        if (! $isRoot && $type instanceof UnionType && $this->canMergeNestedObjectUnion($type)) {
            $type = $this->scope->withShapeMerging(
                callback: fn (): Type => $type->unwrapType($this->scope->config),
            );
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $type->types = array_map(
                callback: fn (Type $member): Type => $this->finalizeResponseType(
                    type: $member,
                    isRoot: false,
                ),
                array: $type->types,
            );

        } else if ($type instanceof ObjectType) {
            $type->properties = array_map(
                callback: fn (Type $property): Type => $this->finalizeResponseType(
                    type: $property,
                    isRoot: false,
                ),
                array: $type->properties,
            );
            $type->typeToDisplay = $type->typeToDisplay === null
                ? null
                : $this->finalizeResponseType(
                    type: $type->typeToDisplay,
                    isRoot: $isRoot,
                );

        } else if ($type instanceof ArrayType) {
            $type->keyType = $type->keyType === null
                ? null
                : $this->finalizeResponseType(type: $type->keyType, isRoot: false);
            $type->itemType = $type->itemType === null
                ? null
                : $this->finalizeResponseType(type: $type->itemType, isRoot: false);
            $type->shape = array_map(
                callback: fn (Type $item): Type => $this->finalizeResponseType(
                    type: $item,
                    isRoot: false,
                ),
                array: $type->shape,
            );
        }

        return $type;
    }


    /**
     * Allows nested snapshots of one concrete class to merge into one shape.
     * Null does not block the merge; anonymous or different classes do.
     */
    private function canMergeNestedObjectUnion(UnionType $type): bool
    {
        $members = array_values(array_filter(
            array: $type->types,
            callback: fn (Type $member): bool => ! $member instanceof NullType,
        ));
        $first = $members[0] ?? null;

        if (! $first instanceof ObjectType || $first->className === null) {
            return false;
        }

        return array_all(
            array: $members,
            callback: fn (Type $member): bool => $member instanceof ObjectType
                && $member->className === $first->className,
        );
    }
}
