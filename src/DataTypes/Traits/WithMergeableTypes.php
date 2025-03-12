<?php declare(strict_types=1);

namespace AutoDoc\DataTypes\Traits;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\VoidType;


trait WithMergeableTypes
{
    public function mergeDuplicateTypes(bool $mergeAsIntersection = false): void
    {
        foreach ($this->types as $i => $type) {
            $this->types[$i] = $type->unwrapType();
        }

        $mergedTypes = [];

        foreach ($this->types as $type) {
            $merged = false;

            foreach ($mergedTypes as $i => $existingType) {
                $mergedType = $this->mergeTypes($existingType, $type, $mergeAsIntersection);

                if ($mergedType) {
                    $mergedTypes[$i] = $mergedType;
                    $merged = true;
                    break;
                }
            }

            if (! $merged) {
                $mergedTypes[] = $type;
            }
        }

        $this->types = $mergedTypes;
    }


    private function mergeTypes(Type $type1, Type $type2, bool $mergeAsIntersection = false): ?Type
    {
        // Converting UnknownType to StringType to prevent `string or string`
        // when there is an union of StringType and UnknownType.
        if ($type1 instanceof UnknownType) {
            $type1 = new StringType;
        }

        if ($type2 instanceof UnknownType) {
            $type2 = new StringType;
        }

        if ($this->isScalarType($type1) && $this->isScalarType($type2)) {
            return $this->mergeScalarTypes($type1, $type2);
        }

        // If type classes do not match, they can not be merged and will be returned as a UnionType.
        if (get_class($type1) !== get_class($type2)) {
            return null;
        }

        if ($type1 instanceof BoolType
            || $type1 instanceof VoidType
            || $type1 instanceof NullType
        ) {
            return $type1;
        }

        if ($type1 instanceof ArrayType) {
            /** @var ArrayType $type2 */
            return $this->mergeArrayTypes($type1, $type2, $mergeAsIntersection);
        }

        if ($type1 instanceof ObjectType) {
            /** @var ObjectType $type2 */
            return $this->mergeObjectTypes($type1, $type2, $mergeAsIntersection);
        }

        return null;
    }


    private function mergeArrayTypes(ArrayType $array1, ArrayType $array2, bool $mergeAsIntersection = false): ?ArrayType
    {
        if ($array1->shape && $array2->shape) {
            $keys1 = array_keys($array1->shape);
            $keys2 = array_keys($array2->shape);

            sort($keys1);
            sort($keys2);

            if ($keys1 !== $keys2) {
                return null;
            }

            foreach ($array1->shape as $key => $type1) {
                $type2 = $array2->shape[$key];

                $mergedType = $this->mergeTypes($type1, $type2);

                if ($mergedType) {
                    $array1->shape[$key] = $mergedType;

                } else if ($mergeAsIntersection) {
                    $array1->shape[$key] = new IntersectionType([$type1, $type2]);

                } else {
                    $array1->shape[$key] = new UnionType([$type1, $type2]);
                }
            }

            return $array1;

        } else if (!$array1->shape && !$array2->shape && $array1->itemType && $array2->itemType) {
            $array1HasStringKeys = $array1->keyType && !($array1->keyType instanceof IntegerType);
            $array2HasStringKeys = $array2->keyType && !($array2->keyType instanceof IntegerType);

            // If only one of both arrays have string keys, types are not mergeable.
            if ($array1HasStringKeys !== $array2HasStringKeys) {
                return null;
            }

            $itemType = $this->mergeTypes($array1->itemType, $array2->itemType);

            if ($itemType) {
                return new ArrayType($itemType);
            }
        }

        return null;
    }

    private function mergeObjectTypes(ObjectType $object1, ObjectType $object2, bool $mergeAsIntersection = false): ?ObjectType
    {
        $keys1 = array_keys($object1->properties);
        $keys2 = array_keys($object2->properties);

        sort($keys1);
        sort($keys2);

        if ($keys1 !== $keys2) {
            return null;
        }

        foreach ($object1->properties as $key => $type1) {
            $type2 = $object2->properties[$key];

            $mergedType = $this->mergeTypes($type1, $type2);

            if ($mergedType) {
                $object1->properties[$key] = $mergedType;

            } else if ($mergeAsIntersection) {
                $object1->properties[$key] = new IntersectionType([$type1, $type2]);

            } else {
                $object1->properties[$key] = new UnionType([$type1, $type2]);
            }
        }

        return $object1;
    }


    /**
     * @phpstan-assert-if-true IntegerType|FloatType|NumberType|StringType $type
     */
    private function isScalarType(Type $type): bool
    {
        return $type instanceof IntegerType
            || $type instanceof FloatType
            || $type instanceof NumberType
            || $type instanceof StringType;
    }


    private function mergeScalarTypes(
        IntegerType|FloatType|NumberType|StringType $type1,
        IntegerType|FloatType|NumberType|StringType $type2,
    ): IntegerType|FloatType|NumberType|StringType|null {

        $t1Values = $type1->getPossibleValues();
        $t2Values = $type2->getPossibleValues();

        $t1IsNumber = $type1 instanceof IntegerType
            || $type1 instanceof FloatType
            || $type1 instanceof NumberType;

        $t2IsNumber = $type2 instanceof IntegerType
            || $type2 instanceof FloatType
            || $type2 instanceof NumberType;

        if ($t1IsNumber && $t2IsNumber) {
            if ($type1::class === $type2::class) {
                $typeClass = $type1::class;

            } else {
                $typeClass = NumberType::class;
            }

        } else if ($type1 instanceof StringType && $type2 instanceof StringType) {
            $typeClass = StringType::class;

        } else {
            return null;
        }

        if (! $t1Values || ! $t2Values) {
            return new $typeClass;
        }

        $possibleValues = array_values(array_unique(array_merge($t1Values, $t2Values)));

        /** @phpstan-ignore-next-line */
        return new $typeClass($possibleValues);
    }
}
