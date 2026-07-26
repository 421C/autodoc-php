<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * @phpstan-type TypeSchema array{
 *     '$ref'?: string,
 *     '$schema'?: string,
 *     '$id'?: string,
 *     '$anchor'?: string,
 *     type?: string|list<string>,
 *     nullable?: bool,
 *     format?: string,
 *     enum?: list<mixed>,
 *     const?: mixed,
 *     multipleOf?: int|float,
 *     maximum?: int|float,
 *     exclusiveMaximum?: bool,
 *     minimum?: int|float,
 *     exclusiveMinimum?: bool,
 *     maxLength?: int,
 *     minLength?: int,
 *     pattern?: string,
 *     maxItems?: int,
 *     minItems?: int,
 *     uniqueItems?: bool,
 *     maxProperties?: int,
 *     minProperties?: int,
 *     required?: list<string>,
 *     properties?: array<string, TypeSchemaRecursive>,
 *     patternProperties?: array<string, TypeSchemaRecursive>,
 *     additionalProperties?: bool|TypeSchemaRecursive,
 *     propertyNames?: TypeSchemaRecursive,
 *     items?: TypeSchemaRecursive|list<TypeSchemaRecursive>,
 *     prefixItems?: list<TypeSchemaRecursive>,
 *     contains?: TypeSchemaRecursive,
 *     minContains?: int,
 *     maxContains?: int,
 *     unevaluatedItems?: bool|TypeSchemaRecursive,
 *     unevaluatedProperties?: bool|TypeSchemaRecursive,
 *     dependentRequired?: array<string, list<string>>,
 *     dependentSchemas?: array<string, TypeSchemaRecursive>,
 *     if?: TypeSchemaRecursive,
 *     then?: TypeSchemaRecursive,
 *     else?: TypeSchemaRecursive,
 *     allOf?: list<TypeSchemaRecursive>,
 *     anyOf?: list<TypeSchemaRecursive>,
 *     oneOf?: list<TypeSchemaRecursive>,
 *     not?: TypeSchemaRecursive,
 *     title?: string,
 *     description?: string,
 *     default?: mixed,
 *     examples?: list<mixed>,
 *     readOnly?: bool,
 *     writeOnly?: bool,
 *     discriminator?: array{propertyName: string, mapping?: array<string, string>},
 *     xml?: array{name?: string, namespace?: string, prefix?: string, attribute?: bool, wrapped?: bool},
 *     externalDocs?: array{description?: string, url: string},
 *     deprecated?: bool,
 *     contentEncoding?: string,
 *     contentMediaType?: string,
 *     contentSchema?: TypeSchemaRecursive,
 *     'x-deprecated-description'?: string,
 * }
 *
 * @phpstan-type TypeSchemaRecursive array<string, mixed>
 */
abstract class Type
{
    /**
     * @return TypeSchema
     */
    abstract public function toSchema(Config $config): array;

    public ?string $description = null;

    /**
     * @var ?array<mixed>
     */
    public ?array $examples = null;

    public bool $required = false;

    public bool $deprecated = false;

    public ?string $deprecatedDescription = null;

    /**
     * @var array<string, mixed>|string|null
     */
    public array|string|null $example = null;

    public bool $isEnum = false;

    public ?string $contentType = null;
    public ?int $httpStatusCode = null;


    /**
     * @return $this
     */
    public function setRequired(bool $required): self
    {
        $this->required = $required;

        return $this;
    }

    /**
     * @param array<float|int|string> $values
     * @return $this
     */
    public function setEnumValues(array $values): self
    {
        $this->isEnum = true;

        if (property_exists($this, 'value')) {
            $this->value = $values;
        }

        return $this;
    }


    public function addDescription(?string $description, bool $prepend = false): self
    {
        if ($prepend) {
            $this->description = trim($description . "\n\n" . $this->description) ?: null;

        } else {
            $this->description = trim($this->description . "\n\n" . $description) ?: null;
        }

        return $this;
    }

    public function addDeprecatedDescription(?string $description): self
    {
        $this->deprecatedDescription = trim($this->deprecatedDescription . "\n\n" . $description) ?: null;

        return $this;
    }

    public function getContentType(): string
    {
        if ($this->contentType) {
            return $this->contentType;
        }

        if ($this instanceof StringType
            || $this instanceof IntegerType
            || $this instanceof FloatType
            || $this instanceof NumberType
            || $this instanceof BoolType
            || $this instanceof NullType
        ) {
            return 'text/plain';
        }

        return 'application/json';
    }

    public function getHttpStatusCode(): int
    {
        if ($this->httpStatusCode) {
            return $this->httpStatusCode;
        }

        return 200;
    }

    public function getSubType(Type $type, Config $config): Type
    {
        if ($this instanceof VoidType || $this instanceof NullType) {
            return $this;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $filteredTypes = array_values(array_filter(
                $type->types,
                fn (Type $t) => $t->isSubTypeOf($this, $config)
            ));

            if (empty($filteredTypes)) {
                return $this;
            }

            $isThisScalar = $this instanceof IntegerType
                || $this instanceof NumberType
                || $this instanceof FloatType
                || $this instanceof BoolType
                || $this instanceof StringType;

            if (count($filteredTypes) !== count($type->types) && $isThisScalar) {
                return $this;
            }

            $type->types = $filteredTypes;

            return $type->unwrapType($config);
        }

        if ($type->isSubTypeOf($this, $config)) {
            return $type;
        }

        return $this;
    }


    public function isSubTypeOf(Type $superType, Config $config): bool
    {
        if ($this instanceof NeverType) {
            return true;
        }

        if ($superType instanceof UnknownType) {
            return true;
        }

        if ($this instanceof UnknownType) {
            return false;
        }

        if ($superType instanceof UnionType) {
            return array_any($superType->types, fn ($type) => $this->isSubTypeOf($type, $config));
        }

        if ($superType instanceof IntersectionType) {
            return array_all($superType->types, fn ($type) => $this->isSubTypeOf($type, $config));
        }

        if ($superType instanceof ClassStringType && $this instanceof ClassStringType) {
            return $superType->className === null || ($this->className && is_a($this->className, $superType->className, true));
        }

        if (($superType instanceof IntegerType && $this instanceof IntegerType)
            || ($superType instanceof FloatType && ($this instanceof FloatType || $this instanceof IntegerType))
            || ($superType instanceof NumberType && ($this instanceof NumberType || $this instanceof IntegerType || $this instanceof FloatType))
            || ($superType instanceof StringType && $this instanceof StringType)
        ) {
            $superValues = $superType->getPossibleValues();

            if (! $superValues) {
                return true;
            }

            $subValues = $this->getPossibleValues();

            if (! $subValues) {
                return false;
            }

            return array_all($subValues, fn ($value) => in_array($value, $superValues, true));
        }

        if ($superType instanceof BoolType && $this instanceof BoolType) {
            return $superType->value === null || $this->value === $superType->value;
        }

        if ($superType instanceof ArrayType && $this instanceof ArrayType) {
            if ($superType->className !== null) {
                if ($this->className === null) {
                    return false;
                }

                if ($this->className !== $superType->className) {
                    if (class_exists($this->className) && class_exists($superType->className)) {
                        if (! is_a($this->className, $superType->className, true)) {
                            return false;
                        }

                    } else {
                        return false;
                    }
                }
            }

            if ($superType->shape) {
                if (! $this->shape) {
                    return false;
                }

                foreach ($superType->shape as $key => $superItemType) {
                    $subItemType = $this->shape[$key] ?? null;

                    if ($subItemType === null) {
                        return false;
                    }

                    if (! $subItemType->isSubTypeOf($superItemType, $config)) {
                        return false;
                    }

                    if ($superItemType->required && ! $subItemType->required) {
                        return false;
                    }
                }

                return true;

            } else if ($this->shape) {
                return true;
            }

            $thisAsTypePair = (clone $this)->convertShapeToTypePair($config);

            if ($superType->keyType !== null) {
                if (! ($thisAsTypePair->keyType ?? new IntegerType)->isSubTypeOf($superType->keyType, $config)) {
                    return false;
                }
            }

            if ($superType->itemType !== null) {
                if ($thisAsTypePair->itemType === null) {
                    return false;
                }

                if (! $thisAsTypePair->itemType->isSubTypeOf($superType->itemType, $config)) {
                    return false;
                }
            }

            return true;
        }

        if ($superType instanceof ObjectType && $this instanceof ObjectType) {
            if ($superType->className !== null) {
                if ($this->className === null) {
                    return false;
                }

                if ($this->className === $superType->className) {
                    return true;

                } else {
                    if (class_exists($this->className) && class_exists($superType->className)) {
                        return is_a($this->className, $superType->className, true);

                    } else {
                        return false;
                    }
                }
            }

            foreach ($superType->properties as $key => $superPropType) {
                $subPropType = $this->properties[$key] ?? null;

                if ($subPropType === null) {
                    return false;
                }

                if (! $subPropType->isSubTypeOf($superPropType, $config)) {
                    return false;
                }

                if ($superPropType->required && ! $subPropType->required) {
                    return false;
                }
            }

            return true;
        }

        if ($superType instanceof NullType) {
            return $this instanceof NullType || $this instanceof VoidType;
        }

        if ($superType instanceof VoidType) {
            return $this instanceof VoidType || $this instanceof NullType;
        }

        if ($superType instanceof CallableType) {
            return $this instanceof CallableType;
        }

        return false;
    }


    public function removeNull(Config $config): Type
    {
        if ($this instanceof UnionType) {
            $types = array_filter($this->types, fn (Type $type) => ! $type instanceof NullType);

            return new UnionType($types)->unwrapType($config);

        } else if ($this instanceof NullType) {
            return new NeverType;
        }

        return $this;
    }


    public function unwrapType(Config $config): Type
    {
        if (is_a($this, UnionType::class) || is_a($this, IntersectionType::class)) {
            if (is_a($this, IntersectionType::class)) {
                if (array_any($this->types, fn (Type $type) => $type instanceof NeverType)) {
                    return new NeverType(conflictingTypes: $this->types, required: $this->required);
                }

                // Distribute over any union member — `A & (B|C)` becomes
                // `(A&B) | (A&C)` — so combinations with no overlap (e.g.
                // `null & int`) collapse to never instead of a bogus allOf.
                $distributed = $this->distributeIntersectionOverUnions($this->types, $config);

                if ($distributed !== null) {
                    return $distributed->unwrapType($config);
                }

            } else if (array_any($this->types, fn (Type $type) => $type instanceof NeverType)) {
                $this->types = array_values(array_filter(
                    $this->types,
                    fn (Type $type) => ! $type instanceof NeverType,
                ));

                if ($this->types === []) {
                    return new NeverType;
                }
            }

            if (count($this->types) === 1) {
                return $this->collapseSingleMemberOnto(reset($this->types), $config);
            }

            if (empty($this->types)) {
                return new UnknownType($this->description);
            }

            $this->mergeDuplicateTypes(mergeAsIntersection: is_a($this, IntersectionType::class), config: $config);

            if (is_a($this, IntersectionType::class)
                && count($this->types) >= 2
                && $this->intersectionIsEmpty($this->types, $config)
            ) {
                return new NeverType(conflictingTypes: $this->types, required: $this->required);
            }

            if (count($this->types) === 1) {
                return $this->collapseSingleMemberOnto(reset($this->types), $config);
            }

        } else if (is_a($this, UnresolvedType::class)) {
            return $this->resolve()->unwrapType($config);
        }

        return $this;
    }


    /**
     * Transfer union/intersection wrapper metadata to the remaining member.
     */
    private function collapseSingleMemberOnto(Type $member, Config $config): Type
    {
        $member = clone $member;

        $member->addDescription($this->description);

        $member->examples = $this->examples ?: $member->examples;
        $member->example = $this->example ?? $member->example;

        $member->required = $member->required || $this->required;
        $member->deprecated = $member->deprecated || $this->deprecated;

        return $member->unwrapType($config);
    }


    /**
     * Expand an intersection over a union member: `A & (B|C)` = `(A&B) | (A&C)`.
     * Returns null when no member is a union (nothing to distribute), a
     * `NeverType` when every branch is empty, otherwise the distributed union.
     *
     * @param Type[] $types
     */
    private function distributeIntersectionOverUnions(array $types, Config $config): ?Type
    {
        $members = array_map(fn (Type $type) => $type->unwrapType($config), $types);

        foreach ($members as $index => $member) {
            if (! $member instanceof UnionType) {
                continue;
            }

            $otherMembers = $members;
            unset($otherMembers[$index]);
            $otherMembers = array_values($otherMembers);

            $branches = [];

            foreach ($member->types as $option) {
                $branch = new IntersectionType([...$otherMembers, $option])->unwrapType($config);

                if (! $branch instanceof NeverType) {
                    $branches[] = $branch;
                }
            }

            return $branches === [] ? new NeverType(conflictingTypes: $types, required: $this->required) : new UnionType($branches);
        }

        return null;
    }


    /**
     * An intersection is empty (`never`) when two of its members can never be
     * satisfied at once. Only flagged for "closed" types (scalars, null, void,
     * array) that don't overlap — object/interface intersections are kept.
     *
     * @param Type[] $types
     */
    private function intersectionIsEmpty(array $types, Config $config): bool
    {
        $types = array_values($types);
        $count = count($types);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $types[$i];
                $b = $types[$j];

                if ($a::class === $b::class
                    || $a->isSubTypeOf($b, $config)
                    || $b->isSubTypeOf($a, $config)
                ) {
                    continue;
                }

                if ($this->isClosedType($a) && $this->isClosedType($b)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * A "closed" type admits no values outside its own kind, so intersecting it
     * with a disjoint type yields `never`. `ClassStringType` is excluded — it
     * overlaps with `string` and with more specific class-strings.
     */
    private function isClosedType(Type $type): bool
    {
        return $type instanceof IntegerType
            || $type instanceof FloatType
            || $type instanceof NumberType
            || ($type instanceof StringType && ! $type instanceof ClassStringType)
            || $type instanceof BoolType
            || $type instanceof NullType
            || $type instanceof VoidType
            || $type instanceof ArrayType;
    }


    /**
     * Resolves lazy types and deep-clones their results before recursive unwrapping can mutate them.
     */
    public function deepResolve(Config $config): Type
    {
        return $this->deepResolveType(config: $config, combineRequired: true);
    }


    private function deepResolveType(Config $config, bool $combineRequired): Type
    {
        if ($this instanceof UnresolvedType) {
            $required = $this->required;
            $resolvedType = $this->resolve();

            if ($resolvedType === $this) {
                return $this;
            }

            $resolvedProjection = null;

            if ($resolvedType instanceof ObjectType
                && ($resolvedType->typeToDisplay instanceof ScalarType
                    || $resolvedType->typeToDisplay instanceof BoolType)
            ) {
                $resolvedProjection = $resolvedType->getScalarDisplayProjection(
                    typeToDisplay: $resolvedType->deepResolveTypeToDisplay($config),
                );
            }

            if ($resolvedProjection !== null) {
                $resolvedProjection->required = $combineRequired
                    ? $resolvedProjection->required || $required
                    : $required;

                return $resolvedProjection;
            }

            $resolvedType = $resolvedType->deepClone();
            $resolvedType->required = $combineRequired
                ? $resolvedType->required || $required
                : $required;

            return $resolvedType->deepResolveType(
                config: $config,
                combineRequired: $combineRequired,
            );
        }

        if ($this instanceof UnionType || $this instanceof IntersectionType) {
            $this->types = array_map(
                callback: fn (Type $type): Type => $type
                    ->deepResolveType(config: $config, combineRequired: true)
                    ->unwrapType($config),
                array: $this->types,
            );

        } else if ($this instanceof ObjectType) {
            $resolvedTypeToDisplay = $this->deepResolveTypeToDisplay($config);
            $projection = $this->getScalarDisplayProjection($resolvedTypeToDisplay);

            if ($projection !== null) {
                return $projection;
            }

            $this->properties = array_map(
                callback: fn (Type $type): Type => $type
                    ->deepResolveType(config: $config, combineRequired: false)
                    ->unwrapType($config),
                array: $this->properties,
            );
            $this->typeToDisplay = $resolvedTypeToDisplay;

        } else if ($this instanceof ArrayType) {
            $this->keyType = $this->keyType
                ?->deepResolveType(config: $config, combineRequired: false)
                ->unwrapType($config);
            $this->itemType = $this->itemType
                ?->deepResolveType(config: $config, combineRequired: false)
                ->unwrapType($config);
            $this->shape = array_map(
                callback: fn (Type $type): Type => $type
                    ->deepResolveType(config: $config, combineRequired: false)
                    ->unwrapType($config),
                array: $this->shape,
            );
        }

        return $this;
    }


    private function deepResolveTypeToDisplay(Config $config): ?Type
    {
        if (! $this instanceof ObjectType || $this->typeToDisplay === null) {
            return null;
        }

        return $this->typeToDisplay
            ->deepClone()
            ->deepResolveType(config: $config, combineRequired: false)
            ->unwrapType($config);
    }


    private function getScalarDisplayProjection(?Type $typeToDisplay): ?ObjectType
    {
        if (! $this instanceof ObjectType) {
            return null;
        }

        if (! $typeToDisplay instanceof ScalarType && ! $typeToDisplay instanceof BoolType) {
            return null;
        }

        $projection = clone $this;
        $projection->properties = [];
        $projection->hiddenProperties = [];
        $projection->typeToDisplay = $typeToDisplay;
        $projection->constructorArgs = null;

        return $projection;
    }


    /**
     * Clone this type and its nested types so the copy can be safely mutated.
     *
     * Resolver context and AST dependencies remain shared.
     */
    public function deepClone(): static
    {
        $type = clone $this;

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $type->types = array_map(
                callback: fn (Type $member): Type => $member->deepClone(),
                array: $type->types,
            );

        } else if ($type instanceof ObjectType) {
            $type->properties = array_map(
                callback: fn (Type $property): Type => $property->deepClone(),
                array: $type->properties,
            );
            $type->hiddenProperties = array_map(
                callback: fn (Type $property): Type => $property->deepClone(),
                array: $type->hiddenProperties,
            );
            $type->typeToDisplay = $type->typeToDisplay?->deepClone();

        } else if ($type instanceof ArrayType) {
            $type->keyType = $type->keyType?->deepClone();
            $type->itemType = $type->itemType?->deepClone();
            $type->shape = array_map(
                callback: fn (Type $item): Type => $item->deepClone(),
                array: $type->shape,
            );

        } else if ($type instanceof NeverType) {
            $type->conflictingTypes = array_map(
                callback: fn (Type $conflictingType): Type => $conflictingType->deepClone(),
                array: $type->conflictingTypes,
            );

        } else if ($type instanceof ClassStringType) {
            $type->classTemplateType = $type->classTemplateType?->deepClone();

        } else if ($type instanceof UnresolvedArrayDimType
            || $type instanceof UnresolvedArrayItemType
            || $type instanceof UnresolvedArrayKeyType
        ) {
            $type->potentialArrayType = $type->potentialArrayType->deepClone();

        } else if ($type instanceof UnresolvedParameterType) {
            $type->argumentType = $type->argumentType->deepClone();

        } else if ($type instanceof UnresolvedClassType) {
            $type->templateTypeValues = array_map(
                callback: fn (?UnresolvedType $templateType): ?UnresolvedType => $templateType?->deepClone(),
                array: $type->templateTypeValues,
            );

        } else if ($type instanceof UnresolvedPhpDocType) {
            $type->fallbackType = $type->fallbackType?->deepClone();
        }

        return $type;
    }


    public static function resolveFromReflection(ReflectionType $reflectionType, ?Scope $scope = null): Type
    {
        if ($reflectionType instanceof ReflectionNamedType) {
            $typeName = $reflectionType->getName();

            $type = match ($reflectionType->getName()) {
                'int' => new IntegerType,
                'float' => new FloatType,
                'string' => new StringType,
                'bool', 'true', 'false' => new BoolType,
                'array' => new ArrayType,
                'object' => new ObjectType,
                'null' => new NullType,
                'never' => new NeverType,
                default => new UnknownType,
            };

            if ($type instanceof UnknownType && class_exists($typeName)) {
                if (isset($scope)) {
                    $type = $scope->getPhpClassInDeeperScope($typeName)->resolveType();

                } else {
                    $type = new ObjectType(className: $typeName);
                }
            }

            if ($reflectionType->allowsNull() && !($type instanceof NullType) && $typeName !== 'mixed') {
                return new UnionType([$type, new NullType]);
            }

            return $type;

        } else if ($reflectionType instanceof ReflectionUnionType) {
            return new UnionType(array_map(fn ($rType) => Type::resolveFromReflection($rType, $scope), $reflectionType->getTypes()));

        } else if ($reflectionType instanceof ReflectionIntersectionType) {
            return new IntersectionType(array_map(fn ($rType) => Type::resolveFromReflection($rType, $scope), $reflectionType->getTypes()));

        } else {
            return new UnknownType;
        }
    }


    /**
     * Build a Type from a literal PHP value, such as a parameter's default value.
     */
    public static function fromValue(mixed $value): Type
    {
        if (is_array($value)) {
            $arrayType = new ArrayType;

            foreach ($value as $key => $item) {
                $arrayType->shape[$key] = self::fromValue($item)->setRequired(true);
            }

            return $arrayType;
        }

        return match (true) {
            $value === null => new NullType,
            is_bool($value) => new BoolType($value),
            is_int($value) => new IntegerType($value),
            is_float($value) => new FloatType($value),
            is_string($value) => new StringType($value),
            default => new UnknownType,
        };
    }
}
