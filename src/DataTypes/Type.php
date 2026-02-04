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
 *     contentSchema?: TypeSchemaRecursive
 * }
 *
 * @phpstan-type TypeSchemaRecursive array<string, mixed>
 */
abstract class Type
{
    /**
     * @return TypeSchema
     */
    abstract public function toSchema(?Config $config = null): array;

    public ?string $description = null;

    /**
     * @var ?array<mixed>
     */
    public ?array $examples = null;

    public bool $required = false;

    public bool $deprecated = false;

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
                fn (Type $t) => $t->isSubTypeOf($this)
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

        if ($type->isSubTypeOf($this)) {
            return $type;
        }

        return $this;
    }


    public function isSubTypeOf(Type $superType): bool
    {
        if ($superType instanceof UnknownType) {
            return true;
        }

        if ($this instanceof UnknownType) {
            return false;
        }

        if ($superType instanceof UnionType) {
            foreach ($superType->types as $type) {
                if ($this->isSubTypeOf($type)) {
                    return true;
                }
            }

            return false;
        }

        if ($superType instanceof IntersectionType) {
            foreach ($superType->types as $type) {
                if (! $this->isSubTypeOf($type)) {
                    return false;
                }
            }

            return true;
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

            foreach ($subValues as $value) {
                if (! in_array($value, $superValues, true)) {
                    return false;
                }
            }

            return true;
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

                    if (! $subItemType->isSubTypeOf($superItemType)) {
                        return false;
                    }

                    if ($superItemType->required && ! $subItemType->required) {
                        return false;
                    }
                }

                return true;
            }

            $thisAsTypePair = (clone $this)->convertShapeToTypePair();

            if ($superType->keyType !== null) {
                if (! ($thisAsTypePair->keyType ?? new IntegerType)->isSubTypeOf($superType->keyType)) {
                    return false;
                }
            }

            if ($superType->itemType !== null) {
                if ($thisAsTypePair->itemType === null) {
                    return false;
                }

                if (! $thisAsTypePair->itemType->isSubTypeOf($superType->itemType)) {
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

                if (! $subPropType->isSubTypeOf($superPropType)) {
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


    public function removeNull(?Config $config = null): Type
    {
        if ($this instanceof UnionType) {
            $types = array_filter($this->types, fn (Type $type) => ! $type instanceof NullType);

            return (new UnionType($types))->unwrapType($config);

        } else if ($this instanceof NullType) {
            return new UnknownType;
        }

        return $this;
    }


    public function unwrapType(?Config $config = null): Type
    {
        if (is_a($this, UnionType::class) || is_a($this, IntersectionType::class)) {
            if (count($this->types) === 1) {
                $type = reset($this->types);

                $type->addDescription($this->description);
                $type->examples = $this->examples ?: $type->examples;
                $type->example = $this->example ?: $type->example;

                $type->required = $type->required || $this->required;
                $type->deprecated = $type->deprecated || $this->deprecated;

                return $type->unwrapType($config);
            }

            if (empty($this->types)) {
                return new UnknownType($this->description);
            }

            $this->mergeDuplicateTypes(mergeAsIntersection: is_a($this, IntersectionType::class), config: $config);

        } else if (is_a($this, UnresolvedType::class)) {
            return $this->resolve()->unwrapType($config);
        }

        return $this;
    }


    public function deepResolve(?Config $config = null): Type
    {
        if (is_a($this, UnionType::class) || is_a($this, IntersectionType::class)) {
            $this->types = array_map(fn (Type $type) => $type->unwrapType($config)->deepResolve($config), $this->types);

        } else if (is_a($this, UnresolvedType::class)) {
            return $this->resolve()->deepResolve($config);

        } else if (is_a($this, ObjectType::class)) {
            $this->properties = array_map(fn (Type $type) => $type->unwrapType($config)->deepResolve($config), $this->properties);
            $this->typeToDisplay = $this->typeToDisplay?->unwrapType($config)->deepResolve($config);

        } else if (is_a($this, ArrayType::class)) {
            $this->keyType = $this->keyType?->unwrapType($config)->deepResolve($config);
            $this->itemType = $this->itemType?->unwrapType($config)->deepResolve($config);
            $this->shape = array_map(fn (Type $type) => $type->unwrapType($config)->deepResolve($config), $this->shape);
        }

        return $this;
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
                default => new UnknownType,
            };

            if ($type instanceof UnknownType && class_exists($typeName)) {
                if (isset($scope)) {
                    $type = $scope->getPhpClassInDeeperScope($typeName)->resolveType();

                } else {
                    $type = new ObjectType(className: $typeName);
                }
            }

            if ($reflectionType->allowsNull() && !($type instanceof NullType)) {
                $type = new UnionType([$type, new NullType]);
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
}
