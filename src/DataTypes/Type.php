<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;


abstract class Type
{
    /**
     * @return array<string, mixed>
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


    public function unwrapType(?Config $config = null): Type
    {
        if (is_a($this, UnionType::class) || is_a($this, IntersectionType::class)) {
            if (count($this->types) === 1) {
                $type = $this->types[0];

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
}
