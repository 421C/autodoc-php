<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

/**
 * Applies `unset($var[...])` to a resolved variable type.
 *
 * @phpstan-import-type PathKey from ArrayType
 */
final readonly class ArrayKeyUnsetApplier
{
    public function __construct(
        private Scope $scope,
    ) {}

    /**
     * A `Type` or `null` path segment is a dynamic key. An uncertain unset makes the key
     * optional instead of removing it.
     *
     * @param non-empty-list<PathKey> $unsetPath
     */
    public function apply(Type $baseType, array $unsetPath, bool $isCertain): Type
    {
        $baseType = $baseType->unwrapType($this->scope->config);

        if ($baseType instanceof UnionType) {
            $baseType = clone $baseType;
            $baseType->types = array_map(
                callback: fn (Type $member): Type => $this->apply($member, $unsetPath, $isCertain),
                array: $baseType->types,
            );

            return $baseType->unwrapType($this->scope->config);
        }

        $key = $unsetPath[0] instanceof Type ? null : $unsetPath[0];
        $remainingPath = array_slice($unsetPath, 1);

        if ($remainingPath === []) {
            if (! $baseType instanceof ArrayType) {
                return $baseType;
            }

            $arrayType = clone $baseType;

            return $key === null ? $arrayType->markItemsOptional() : $arrayType->removeItemFromArray($key, $isCertain);
        }

        if ($baseType instanceof ArrayType) {
            return $this->unsetInElement($baseType, $key, $remainingPath, $isCertain);
        }

        if ($baseType instanceof ObjectType && $key !== null) {
            return $this->unsetInProperty($baseType, $key, $remainingPath, $isCertain);
        }

        return $baseType;
    }


    /**
     * @param non-empty-list<PathKey> $remainingPath
     */
    private function unsetInElement(ArrayType $arrayType, int|string|null $key, array $remainingPath, bool $isCertain): ArrayType
    {
        $arrayType = clone $arrayType;

        if ($key !== null && isset($arrayType->shape[$key])) {
            $arrayType->shape[$key] = $this->applyKeepingRequired($arrayType->shape[$key], $remainingPath, $isCertain);

            return $arrayType;
        }

        if ($key === null) {
            foreach ($arrayType->shape as $shapeKey => $entry) {
                $arrayType->shape[$shapeKey] = $this->applyKeepingRequired($entry, $remainingPath, isCertain: false);
            }
        }

        if ($arrayType->itemType !== null) {
            $arrayType->itemType = $this->applyKeepingRequired($arrayType->itemType, $remainingPath, isCertain: false);
        }

        return $arrayType;
    }


    /**
     * @param non-empty-list<PathKey> $remainingPath
     */
    private function unsetInProperty(ObjectType $objectType, int|string $key, array $remainingPath, bool $isCertain): ObjectType
    {
        $propertyType = $this->scope->getObjectPropertyType($objectType, $key);

        if ($propertyType === null) {
            return $objectType;
        }

        $objectType = clone $objectType;
        $objectType->properties[(string) $key] = $this->applyKeepingRequired($propertyType, $remainingPath, $isCertain);

        return $objectType;
    }


    /**
     * @param non-empty-list<PathKey> $unsetPath
     */
    private function applyKeepingRequired(Type $type, array $unsetPath, bool $isCertain): Type
    {
        return (clone $this->apply($type, $unsetPath, $isCertain))->setRequired($type->required);
    }
}
