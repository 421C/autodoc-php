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


    public function unwrapType(?Config $config = null): Type
    {
        if (is_a($this, UnionType::class) || is_a($this, IntersectionType::class)) {
            if (count($this->types) === 1) {
                $type = $this->types[0];

                $type->description = $type->description ?: $this->description;
                $type->examples = $type->examples ?: $this->examples;

                return $type->unwrapType($config);
            }

            if (empty($this->types)) {
                return new UnknownType($this->description);
            }

            $this->mergeDuplicateTypes(mergeAsIntersection: is_a($this, IntersectionType::class), config: $config);

        } else if (is_a($this, UnresolvedType::class)) {
            return $this->resolve();
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
