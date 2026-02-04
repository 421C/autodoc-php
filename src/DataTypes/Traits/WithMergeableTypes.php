<?php declare(strict_types=1);

namespace AutoDoc\DataTypes\Traits;

use AutoDoc\Config;
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
    public function mergeObjectsAndArrayShapes(?Config $config = null): self
    {
        $types = [];

        $objectType = null;

        foreach ($this->types as $type) {
            $type = $type->unwrapType($config);

            if ($type instanceof ObjectType || ($type instanceof ArrayType && $type->shape)) {
                if ($type instanceof ArrayType) {
                    $properties = [];

                    foreach ($type->shape as $key => $valueType) {
                        $properties[(string) $key] = $valueType;
                    }

                } else {
                    $properties = $type->properties;
                }

                if ($objectType) {
                    foreach ($properties as $key => $valueType) {
                        $existingValueType = $objectType->properties[$key] ?? null;

                        if (! $existingValueType) {
                            $objectType->properties[$key] = $valueType;

                        } else {
                            $mergedType = $this->mergeTypes($existingValueType, $valueType);

                            if ($mergedType) {
                                $mergedType->required = $existingValueType->required || $valueType->required;

                                $objectType->properties[$key] = $mergedType;

                            } else {
                                $objectType->properties[$key] = (new IntersectionType([$existingValueType, $valueType]))
                                    ->setRequired($existingValueType->required || $valueType->required);
                            }
                        }
                    }

                } else {
                    $objectType = $type instanceof ArrayType ? new ObjectType($properties) : $type;
                }

            } else {
                $types[] = $type;
            }
        }

        if ($objectType) {
            $types[] = $objectType;
        }

        $this->types = $types;

        return $this;
    }

    public function mergeDuplicateTypes(bool $mergeAsIntersection = false, ?Config $config = null): void
    {
        $types = [];

        foreach ($this->types as $type) {
            $type = $type->unwrapType($config);

            if (($mergeAsIntersection && $type instanceof IntersectionType)
                || (! $mergeAsIntersection && $type instanceof UnionType)
            ) {
                foreach ($type->types as $type) {
                    $types[] = $type;
                }

            } else {
                $types[] = $type;
            }
        }

        $mergedTypes = [];

        foreach ($types as $type) {
            $merged = false;

            foreach ($mergedTypes as $i => $existingType) {
                $mergedType = $this->mergeTypes($existingType, $type, $mergeAsIntersection, $config);

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

        /**
         * Move NullType to the end of type list, so that it looks better in TS export.
         */
        $nonNullTypes = [];
        $nullType = null;

        foreach ($mergedTypes as $type) {
            if ($type instanceof NullType) {
                $nullType = $type;

            } else {
                $nonNullTypes[] = $type;
            }
        }

        $this->types = $nonNullTypes;

        if ($nullType) {
            $this->types[] = $nullType;
        }
    }


    private function mergeTypes(Type $type1, Type $type2, bool $mergeAsIntersection = false, ?Config $config = null): ?Type
    {
        if ($type1->getHttpStatusCode() !== $type2->getHttpStatusCode()) {
            return null;
        }

        // Converting UnknownType to StringType to prevent `string or string`
        // when there is an union of StringType and UnknownType.
        if ($type1 instanceof UnknownType && $type2 instanceof StringType) {
            $type1 = new StringType;
        }

        if ($type2 instanceof UnknownType && $type1 instanceof StringType) {
            $type2 = new StringType;
        }

        if ($this->isScalarType($type1) && $this->isScalarType($type2)) {
            return $this->mergeScalarTypes($type1, $type2, $config);
        }

        // If type classes do not match, they can not be merged and will be returned as a UnionType.
        if (get_class($type1) !== get_class($type2)) {
            return null;
        }

        if ($type1 instanceof BoolType) {
            /** @var BoolType $type2 */
            $type1->value = $type1->value === $type2->value ? $type1->value : null;

            return $type1;
        }

        if ($type1 instanceof VoidType
            || $type1 instanceof NullType
        ) {
            return $type1;
        }

        if ($type1 instanceof ArrayType) {
            /** @var ArrayType $type2 */
            return $this->mergeArrayTypes($type1, $type2, $mergeAsIntersection, $config);
        }

        if ($type1 instanceof ObjectType) {
            /** @var ObjectType $type2 */
            return $this->mergeObjectTypes($type1, $type2, $mergeAsIntersection, $config);
        }

        return null;
    }


    private function mergeArrayTypes(ArrayType $array1, ArrayType $array2, bool $mergeAsIntersection = false, ?Config $config = null): ?ArrayType
    {
        if (! $array1->shape && ! $array1->itemType && ! $array1->keyType) {
            return $array2;
        }

        if (! $array2->shape && ! $array2->itemType && ! $array2->keyType) {
            return $array1;
        }

        if ($array1->shape && $array2->shape) {
            $mergeShapesInTypeUnions = $config?->data['arrays']['merge_shapes_in_type_unions'] ?? false;

            if (! $mergeAsIntersection && ! $mergeShapesInTypeUnions) {
                $keys1 = array_keys($array1->shape);
                $keys2 = array_keys($array2->shape);

                sort($keys1);
                sort($keys2);

                if ($keys1 !== $keys2) {
                    return null;
                }
            }

            foreach ($array1->shape as $key => $type1) {
                if (! isset($array2->shape[$key])) {
                    continue;
                }

                $type2 = $array2->shape[$key];

                $mergedType = $this->mergeTypes($type1, $type2);

                if ($mergedType) {
                    if ($mergeAsIntersection) {
                        $mergedType->required = $type1->required || $type2->required;

                    } else {
                        $mergedType->required = $type1->required && $type2->required;
                    }

                    $array1->shape[$key] = $mergedType;

                } else if ($mergeAsIntersection) {
                    $array1->shape[$key] = new IntersectionType([$type1, $type2]);

                } else {
                    $array1->shape[$key] = new UnionType([$type1, $type2]);
                }
            }

            if ($mergeAsIntersection || $mergeShapesInTypeUnions) {
                foreach ($array2->shape as $key => $type2) {
                    if (!isset($array1->shape[$key])) {
                        $array1->shape[$key] = $type2;
                    }
                }
            }

            return $array1;

        } else if (!$array1->shape && !$array2->shape) {
            if ($array1->itemType && $array2->itemType) {
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

            } else if ($array1->itemType) {
                return $array1;

            } else {
                return $array2;
            }
        }

        return null;
    }

    private function mergeObjectTypes(ObjectType $object1, ObjectType $object2, bool $mergeAsIntersection = false, ?Config $config = null): ?ObjectType
    {
        $mergeShapesInTypeUnions = $config?->data['objects']['merge_shapes_in_type_unions'] ?? false;

        if (! $mergeAsIntersection && ! $mergeShapesInTypeUnions) {
            $keys1 = array_keys($object1->properties);
            $keys2 = array_keys($object2->properties);

            sort($keys1);
            sort($keys2);

            if ($keys1 !== $keys2) {
                return null;
            }
        }

        foreach ($object1->properties as $key => $type1) {
            if (! isset($object2->properties[$key])) {
                continue;
            }

            $type2 = $object2->properties[$key];

            $mergedType = $this->mergeTypes($type1, $type2);

            if ($mergedType) {
                if ($mergeAsIntersection) {
                    $mergedType->required = $type1->required || $type2->required;

                } else {
                    $mergedType->required = $type1->required && $type2->required;
                }

                $object1->properties[$key] = $mergedType;

            } else if ($mergeAsIntersection) {
                $object1->properties[$key] = (new IntersectionType([$type1, $type2]))->setRequired($type1->required || $type2->required);

            } else {
                $object1->properties[$key] = new UnionType([$type1, $type2]);
            }
        }

        if ($mergeAsIntersection || $mergeShapesInTypeUnions) {
            foreach ($object2->properties as $key => $type2) {
                if (!isset($object1->properties[$key])) {
                    $object1->properties[$key] = $type2;
                }
            }
        }

        if ($object1->typeToDisplay || $object2->typeToDisplay) {
            $object1->typeToDisplay = (new UnionType(array_values(array_filter([$object1->typeToDisplay, $object2->typeToDisplay]))))->unwrapType($config);
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
        ?Config $config = null,
    ): IntegerType|FloatType|NumberType|StringType|null {

        $t1IsNumber = $type1 instanceof IntegerType
            || $type1 instanceof FloatType
            || $type1 instanceof NumberType;

        $t2IsNumber = $type2 instanceof IntegerType
            || $type2 instanceof FloatType
            || $type2 instanceof NumberType;

        if ($t1IsNumber && $t2IsNumber) {
            if ($type1::class === $type2::class) {
                $typeClassName = $type1::class;
                $resultType = new $typeClassName;

            } else {
                $resultType = new NumberType;
            }

            $resultType->description = $type1->description === $type2->description ? $type1->description : null;
            $resultType->minimum = $type1->minimum === $type2->minimum ? $type1->minimum : null;
            $resultType->maximum = $type1->maximum === $type2->maximum ? $type1->maximum : null;

        } else if ($type1 instanceof StringType && $type2 instanceof StringType) {
            $resultType = new StringType(
                description: $type1->description === $type2->description ? $type1->description : null,
                format: $type1->format === $type2->format ? $type1->format : null,
                minLength: $type1->minLength === $type2->minLength ? $type1->minLength : null,
                maxLength: $type1->maxLength === $type2->maxLength ? $type1->maxLength : null,
                pattern: $type1->pattern === $type2->pattern ? $type1->pattern : null,
            );

        } else {
            return null;
        }

        $resultType->required = $this->required || $type1->required || $type2->required;

        if ($this->isEnum || ($config?->data['openapi']['show_values_for_scalar_types'] ?? false)) {
            $t1Values = $type1->getPossibleValues();
            $t2Values = $type2->getPossibleValues();

            if (($t1Values && $t2Values) || ! ($config?->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? true)) {
                $possibleValues = array_values(array_unique(array_merge($t1Values ?? [], $t2Values ?? [])));

                $resultType->setEnumValues($possibleValues);
            }
        }

        return $resultType;
    }

    /**
     * @param array<mixed> $array
     * @return list<string>
     */
    private function flattenArrayOfStrings(array $array): array
    {
        $strings = [];

        array_walk_recursive($array, function ($value) use (&$strings) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        return $strings;
    }
}
