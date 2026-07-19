<?php declare(strict_types=1);

namespace AutoDoc\DataTypes\Traits;

use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\ScalarType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\VoidType;


trait WithMergeableTypes
{
    /**
     * Resolves coercible scalar intersections like `string&numeric`; returns null
     * when normal merge/intersection rules should apply.
     */
    private function coerceScalarOverlap(Type $type1, Type $type2): ?Type
    {
        $isNumber = fn (Type $type): bool => $type instanceof IntegerType
            || $type instanceof FloatType
            || $type instanceof NumberType;

        // string ∩ numeric → a numeric string ("123", "-4.5")
        if (($type1 instanceof StringType && $isNumber($type2))
            || ($type2 instanceof StringType && $isNumber($type1))
        ) {
            $numeric = $type1 instanceof StringType ? $type2 : $type1;

            if ($numeric instanceof IntegerType) {
                $result = new IntegerType;
                $result->isString = true;

                return $result;
            }

            return new NumberType(isString: true);
        }

        // bool ∩ numeric → 0/1
        if (($type1 instanceof BoolType && $isNumber($type2))
            || ($type2 instanceof BoolType && $isNumber($type1))
        ) {
            $numeric = $type1 instanceof BoolType ? $type2 : $type1;

            $result = $numeric instanceof IntegerType ? new IntegerType : new NumberType;
            $result->setEnumValues([0, 1]);

            return $result;
        }

        // bool ∩ string → "0"/"1"
        if (($type1 instanceof BoolType && $type2 instanceof StringType)
            || ($type2 instanceof BoolType && $type1 instanceof StringType)
        ) {
            $result = new StringType;
            $result->setEnumValues(['0', '1']);

            return $result;
        }

        return null;
    }

    private function arrayShapeToObject(ArrayType $array): ObjectType
    {
        $properties = [];

        foreach ($array->shape as $key => $valueType) {
            $properties[(string) $key] = $valueType;
        }

        return new ObjectType($properties)->setRequired($array->required);
    }

    private function asOptionalMember(Type $type): Type
    {
        return (clone $type)->setRequired(false);
    }

    public function mergeDuplicateTypes(Config $config, bool $mergeAsIntersection = false): void
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

        // When a union contains an unknown type alongside scalars, the scalars can
        // no longer claim exact values — the unknown may stand for other possible
        // values — so drop their const/enum values. This matches how unknown is
        // already absorbed into a sibling string type.
        if (! $mergeAsIntersection
            && ($config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? true)
            && array_any($types, fn (Type $type) => $type instanceof UnknownType)
        ) {
            foreach ($types as $type) {
                if ($type instanceof IntegerType
                    || $type instanceof FloatType
                    || $type instanceof NumberType
                    || $type instanceof StringType
                    || $type instanceof BoolType
                ) {
                    $type->value = null;
                    $type->isEnum = false;
                }
            }
        }

        $mergedTypes = [];

        foreach ($types as $type) {
            $merged = false;

            foreach ($mergedTypes as $i => $existingType) {
                $mergedType = $this->mergeTypes($existingType, $type, $config, $mergeAsIntersection);

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


    private function mergeTypes(Type $type1, Type $type2, Config $config, bool $mergeAsIntersection = false): ?Type
    {
        if ($type1->getHttpStatusCode() !== $type2->getHttpStatusCode()) {
            return null;
        }

        // Intersecting UnknownType with anything only leaves the other operand.
        if ($mergeAsIntersection && ($type1 instanceof UnknownType || $type2 instanceof UnknownType)) {
            $unknown = $type1 instanceof UnknownType ? $type1 : $type2;
            $other = $type1 instanceof UnknownType ? $type2 : $type1;

            return (clone $other)
                ->addDescription($unknown->description)
                ->setRequired($this->required || $type1->required || $type2->required);
        }

        // Converting UnknownType to StringType to prevent `string or string`
        // when there is an union of StringType and UnknownType.
        if ($type1 instanceof UnknownType && $type2 instanceof StringType) {
            $type1 = new StringType;
        }

        if ($type2 instanceof UnknownType && $type1 instanceof StringType) {
            $type2 = new StringType;
        }

        // Validation semantics allow coercible scalar intersections like `string&number`.
        if ($mergeAsIntersection && ($config->data['intersections']['coercive_scalar_overlap'] ?? false)) {
            $overlap = $this->coerceScalarOverlap($type1, $type2);

            if ($overlap !== null) {
                $overlap->required = $this->required || $type1->required || $type2->required;

                return $overlap;
            }
        }

        if ($this->isScalarType($type1) && $this->isScalarType($type2)) {
            return $this->mergeScalarTypes($type1, $type2, $config, $mergeAsIntersection);
        }

        // Intersected array-shapes and objects both emit `type: object`, so merge
        // cross-kind pairs into one object shape instead of leaving an allOf.
        if ($mergeAsIntersection) {
            if ($type1 instanceof ArrayType && $type1->shape && $type2 instanceof ObjectType) {
                $type1 = $this->arrayShapeToObject($type1);
            }

            if ($type2 instanceof ArrayType && $type2->shape && $type1 instanceof ObjectType) {
                $type2 = $this->arrayShapeToObject($type2);
            }
        }

        // If type classes do not match, they can not be merged and will be returned as a UnionType.
        if ($type1::class !== $type2::class) {
            return null;
        }

        if ($type1 instanceof BoolType) {
            /** @var BoolType $type2 */
            if ($mergeAsIntersection && $type1->value !== null && $type2->value !== null && $type1->value !== $type2->value) {
                return null;
            }

            $type1->value = $mergeAsIntersection
                ? ($type1->value ?? $type2->value)
                : ($type1->value === $type2->value ? $type1->value : null);
            $type1->required = $this->required || $type1->required || $type2->required;

            return $type1;
        }

        if ($type1 instanceof VoidType
            || $type1 instanceof NullType
        ) {
            return $type1;
        }

        if ($type1 instanceof ArrayType) {
            /** @var ArrayType $type2 */
            return $this->mergeArrayTypes($type1, $type2, $config, $mergeAsIntersection);
        }

        if ($type1 instanceof ObjectType) {
            /** @var ObjectType $type2 */
            return $this->mergeObjectTypes($type1, $type2, $config, $mergeAsIntersection);
        }

        return null;
    }


    private function mergeArrayTypes(ArrayType $array1, ArrayType $array2, Config $config, bool $mergeAsIntersection = false): ?ArrayType
    {
        if (! $array1->shape && ! $array1->itemType && ! $array1->keyType) {
            if (! $mergeAsIntersection) {
                foreach ($array2->shape as $key => $type) {
                    $array2->shape[$key] = $this->asOptionalMember($type);
                }
            }

            return $array2;
        }

        if (! $array2->shape && ! $array2->itemType && ! $array2->keyType) {
            if (! $mergeAsIntersection) {
                foreach ($array1->shape as $key => $type) {
                    $array1->shape[$key] = $this->asOptionalMember($type);
                }
            }

            return $array1;
        }

        if ($array1->shape && $array2->shape) {
            $mergeShapesInTypeUnions = $config->data['arrays']['merge_shapes_in_type_unions'] ?? false;

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
                    if (! $mergeAsIntersection) {
                        $array1->shape[$key] = $this->asOptionalMember($type1);
                    }

                    continue;
                }

                $type2 = $array2->shape[$key];

                $mergedType = $this->mergeTypes($type1, $type2, $config, $mergeAsIntersection);

                if ($mergedType) {
                    if ($mergeAsIntersection) {
                        $mergedType->required = $type1->required || $type2->required;

                    } else {
                        $mergedType->required = $type1->required && $type2->required;
                    }

                    $array1->shape[$key] = $mergedType;

                } else if ($mergeAsIntersection) {
                    $array1->shape[$key] = new IntersectionType([$type1, $type2])
                        ->setRequired($type1->required || $type2->required);

                } else {
                    $array1->shape[$key] = new UnionType([$type1, $type2])
                        ->setRequired($type1->required && $type2->required);
                }
            }

            if ($mergeAsIntersection || $mergeShapesInTypeUnions) {
                foreach ($array2->shape as $key => $type2) {
                    if (!isset($array1->shape[$key])) {
                        $array1->shape[$key] = $mergeAsIntersection
                            ? $type2
                            : $this->asOptionalMember($type2);
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

                $itemType = $this->mergeTypes($array1->itemType, $array2->itemType, $config, $mergeAsIntersection);

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

    private function mergeObjectTypes(ObjectType $object1, ObjectType $object2, Config $config, bool $mergeAsIntersection = false): ?ObjectType
    {
        if ($mergeAsIntersection) {
            if ($object1->className === null) {
                $object1->className = $object2->className;

            } else if ($object2->className !== null) {
                if ($object1->className === $object2->className || is_a($object1->className, $object2->className, true)) {
                    // Keep the already-more-specific class.

                } else if (is_a($object2->className, $object1->className, true)) {
                    $object1->className = $object2->className;

                } else {
                    return null;
                }
            }
        }

        // Folding unrelated classes into one object would lose the class identity
        // that method resolution and instanceof narrowing rely on.
        if (! $mergeAsIntersection
            && $object1->className !== null
            && $object2->className !== null
            && ! is_a($object1->className, $object2->className, true)
            && ! is_a($object2->className, $object1->className, true)
        ) {
            return null;
        }

        $mergeShapesInTypeUnions = $config->data['objects']['merge_shapes_in_type_unions'] ?? false;

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
                if (! $mergeAsIntersection) {
                    $object1->properties[$key] = $this->asOptionalMember($type1);
                }

                continue;
            }

            $type2 = $object2->properties[$key];

            $mergedType = $this->mergeTypes($type1, $type2, $config, $mergeAsIntersection);

            if ($mergedType) {
                if ($mergeAsIntersection) {
                    $mergedType->required = $type1->required || $type2->required;

                } else {
                    $mergedType->required = $type1->required && $type2->required;
                }

                $object1->properties[$key] = $mergedType;

            } else if ($mergeAsIntersection) {
                $object1->properties[$key] = new IntersectionType([$type1, $type2])->setRequired($type1->required || $type2->required);

            } else {
                $object1->properties[$key] = new UnionType([$type1, $type2])->setRequired($type1->required && $type2->required);
            }
        }

        if ($mergeAsIntersection || $mergeShapesInTypeUnions) {
            foreach ($object2->properties as $key => $type2) {
                if (!isset($object1->properties[$key])) {
                    $object1->properties[$key] = $mergeAsIntersection
                        ? $type2
                        : $this->asOptionalMember($type2);
                }
            }
        }

        if ($object1->typeToDisplay || $object2->typeToDisplay) {
            $object1->typeToDisplay = new UnionType(array_values(array_filter([$object1->typeToDisplay, $object2->typeToDisplay])))->unwrapType($config);
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
        Config $config,
        bool $mergeAsIntersection = false,
    ): IntegerType|FloatType|NumberType|StringType|NeverType|null {
        $t1IsNumber = $type1 instanceof IntegerType
            || $type1 instanceof FloatType
            || $type1 instanceof NumberType;

        $t2IsNumber = $type2 instanceof IntegerType
            || $type2 instanceof FloatType
            || $type2 instanceof NumberType;

        if ($t1IsNumber && $t2IsNumber) {
            if ($mergeAsIntersection && $type1 instanceof NumberType) {
                $typeClassName = $type2::class;
                $resultType = new $typeClassName;

            } else if ($mergeAsIntersection && $type2 instanceof NumberType) {
                $typeClassName = $type1::class;
                $resultType = new $typeClassName;

            } else if ($type1::class === $type2::class) {
                $typeClassName = $type1::class;
                $resultType = new $typeClassName;

            } else if ($mergeAsIntersection) {
                return null;

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

        $t1Values = $type1->getPossibleValues();
        $t2Values = $type2->getPossibleValues();

        $canRepresentLiteralValues = ScalarType::canRepresentLiteralValues($t1Values ?? [])
            && ScalarType::canRepresentLiteralValues($t2Values ?? []);

        if ($mergeAsIntersection) {
            $possibleValues = $this->intersectPossibleScalarValues($t1Values, $t2Values);

            if ($possibleValues === []) {
                if (! $canRepresentLiteralValues) {
                    return new NeverType(
                        conflictingTypes: [$type1, $type2],
                        required: $resultType->required,
                    );
                }

                return null;
            }

            if ($possibleValues !== null && ScalarType::canRepresentLiteralValues($possibleValues)) {
                $resultType->setEnumValues($possibleValues);
                $resultType->isEnum = $this->isEnum || $type1->isEnum || $type2->isEnum;
            }

        } else if ($this->isEnum || ($config->data['openapi']['show_values_for_scalar_types'] ?? false)) {
            if ($canRepresentLiteralValues
                && (($t1Values && $t2Values) || ! ($config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? true))
            ) {
                $possibleValues = $this->uniqueScalarValues(array_merge($t1Values ?? [], $t2Values ?? []));

                $resultType->setEnumValues($possibleValues);
            }
        }

        return $resultType;
    }


    /**
     * @param list<float|int|string> $values
     * @return list<float|int|string>
     */
    private function uniqueScalarValues(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (! array_any($unique, fn (float|int|string $uniqueValue) => $this->scalarValuesAreEquivalent($value, $uniqueValue))) {
                $unique[] = $value;
            }
        }

        return $unique;
    }

    /**
     * @param list<float|int|string>|null $values1
     * @param list<float|int|string>|null $values2
     * @return list<float|int|string>|null
     */
    private function intersectPossibleScalarValues(?array $values1, ?array $values2): ?array
    {
        if ($values1 === null && $values2 === null) {
            return null;
        }

        if ($values1 === null) {
            return $values2;
        }

        if ($values2 === null) {
            return $values1;
        }

        return array_values(array_filter(
            $values1,
            fn (float|int|string $value) => array_any(
                $values2,
                fn (float|int|string $otherValue) => $this->scalarValuesAreEquivalent($value, $otherValue),
            ),
        ));
    }


    private function scalarValuesAreEquivalent(
        float|int|string $value1,
        float|int|string $value2,
    ): bool {
        if (is_string($value1) || is_string($value2)) {
            return $value1 === $value2;
        }

        $value1IsNonFinite = is_float($value1) && ! is_finite($value1);
        $value2IsNonFinite = is_float($value2) && ! is_finite($value2);

        if ($value1IsNonFinite || $value2IsNonFinite) {
            return (is_float($value1) && is_nan($value1) && is_float($value2) && is_nan($value2))
                || $value1 === $value2;
        }

        return json_encode($value1) === json_encode($value2);
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
