<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

final readonly class TypeNarrowingApplier
{
    public function __construct(
        private Scope $scope,
    ) {}

    /**
     * Apply a narrowing either directly to a type or to a descendant attribute
     * path inside it. Distributes attribute narrowings over unions so impossible
     * variants become `NeverType` and are dropped by union unwrapping.
     *
     * @param list<int|string> $path
     */
    public function applyPath(Type $base, array $path, Narrowing $narrowing): Type
    {
        $base = $base->unwrapType($this->scope->config);

        if ($path === []) {
            return $narrowing->apply($base, $this->scope)->unwrapType($this->scope->config);
        }

        return $this->applyAttributePath($base, $path, $narrowing);
    }

    /**
     * Apply a narrowing to a literal attribute path, leaving the rest untouched.
     * Distributes over unions.
     *
     * @param non-empty-list<int|string> $path
     */
    public function applyAttributePath(Type $base, array $path, Narrowing $narrowing): Type
    {
        $base = $base->unwrapType($this->scope->config);

        if ($base instanceof UnionType) {
            $base = clone $base;
            $base->types = array_map(
                fn (Type $type): Type => $this->applyAttributePath($type, $path, $narrowing),
                $base->types,
            );

            return $base->unwrapType($this->scope->config);
        }

        $key = $path[0];
        $remainingPath = array_slice($path, 1);

        if ($base instanceof ObjectType) {
            $propertyType = $this->resolveObjectPropertyType($base, $key);

            if ($propertyType === null) {
                return $base;
            }

            $narrowedPropertyType = $this->narrowAttributeValue($propertyType, $remainingPath, $narrowing);

            if ($narrowedPropertyType instanceof NeverType) {
                return new NeverType;
            }

            $base = clone $base;
            $base->properties[(string) $key] = $narrowedPropertyType;

            return $base;
        }

        if ($base instanceof ArrayType) {
            $elementType = $base->shape[$key] ?? $base->itemType;

            if ($elementType === null) {
                return $base;
            }

            $narrowedElementType = $this->narrowAttributeValue(
                $elementType->unwrapType($this->scope->config),
                $remainingPath,
                $narrowing,
            );

            if ($narrowedElementType instanceof NeverType) {
                return new NeverType;
            }

            $base = clone $base;
            $base->shape[$key] = $narrowedElementType;

            return $base;
        }

        return $base;
    }

    /**
     * Narrow a single attribute value (property or shape element), recursing into
     * the remaining path. Narrowing only refines the value's type, so the original
     * `required` flag is kept - unless the narrowing asserts presence at the leaf
     * (e.g. `isset($arr['key'])`), which makes the key required.
     *
     * @param list<int|string> $path
     */
    private function narrowAttributeValue(Type $current, array $path, Narrowing $narrowing): Type
    {
        $wasRequired = $current->required;

        if ($path === []) {
            $narrowed = $this->applyPath($current, [], $narrowing);

            if ($narrowed instanceof NeverType) {
                return $narrowed;
            }

            $narrowed->required = $narrowing->assertsPresence() ? true : $wasRequired;

        } else {
            $narrowed = $this->applyAttributePath($current, $path, $narrowing);

            if ($narrowed instanceof NeverType) {
                return $narrowed;
            }

            $narrowed->required = $wasRequired;
        }

        return $narrowed;
    }

    private function resolveObjectPropertyType(ObjectType $objectType, int|string $key): ?Type
    {
        $keyString = (string) $key;

        if (isset($objectType->properties[$keyString])) {
            return $objectType->properties[$keyString]->unwrapType($this->scope->config);
        }

        if ($objectType->className !== null) {
            return $this->scope->getPhpClass($objectType->className)->getProperty($keyString)?->unwrapType($this->scope->config);
        }

        return null;
    }
}
