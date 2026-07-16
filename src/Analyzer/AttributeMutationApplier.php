<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;

/**
 * Applies attribute mutations to a resolved variable type.
 */
final readonly class AttributeMutationApplier
{
    public function __construct(
        private Scope $scope,
    ) {}

    /**
     * Merge attributes into `$baseType`, optionally at a literal `$path`.
     * `$isCertain` makes the mutation unconditional and the attributes required.
     *
     * @param list<int|string> $path
     * @param array<int|string, Type> $attributes
     */
    public function apply(?Type $baseType, array $path, array $attributes, bool $isCertain): ?Type
    {
        if ($attributes === []) {
            return $baseType;
        }

        if ($path === []) {
            foreach ($attributes as $key => $attributeType) {
                $baseType = $this->mergeAttribute($baseType, $key, $attributeType, $isCertain);
            }

            return $baseType;
        }

        if ($baseType === null) {
            return null;
        }

        $baseType = $baseType->unwrapType($this->scope->config);

        if ($baseType instanceof UnionType) {
            $baseType = clone $baseType;

            $baseType->types = array_map(
                fn (Type $typeVariant): Type => $this->apply($typeVariant, $path, $attributes, $isCertain) ?? $typeVariant,
                $baseType->types,
            );

            return $baseType->unwrapType($this->scope->config);
        }

        $pathKey = $path[0];
        $remainingPath = array_slice($path, 1);

        if ($baseType instanceof ObjectType) {
            $propertyType = $this->resolveObjectPropertyType($baseType, $pathKey);

            if ($propertyType === null) {
                return $baseType;
            }

            $wasRequired = $propertyType->required;

            $mergedPropertyType = $this->apply(
                baseType: $propertyType,
                path: $remainingPath,
                attributes: $attributes,
                isCertain: $isCertain,
            );

            if ($mergedPropertyType === null) {
                return $baseType;
            }

            $baseType = clone $baseType;
            $baseType->properties[(string) $pathKey] = $mergedPropertyType->setRequired($wasRequired);

            return $baseType;
        }

        if ($baseType instanceof ArrayType) {
            $elementType = $baseType->shape[$pathKey] ?? $baseType->itemType;

            if ($elementType === null) {
                return $baseType;
            }

            $wasRequired = $elementType->required;

            $mergedElementType = $this->apply(
                baseType: $elementType->unwrapType($this->scope->config),
                path: $remainingPath,
                attributes: $attributes,
                isCertain: $isCertain,
            );

            if ($mergedElementType === null) {
                return $baseType;
            }

            $baseType = clone $baseType;

            if ($baseType->shape) {
                $baseType->shape[$pathKey] = $mergedElementType->setRequired($wasRequired);

            } else {
                $baseType->itemType = $mergedElementType->setRequired($wasRequired);
            }

            return $baseType;
        }

        return $baseType;
    }


    private function mergeAttribute(?Type $baseType, int|string $key, Type $attributeType, bool $isCertain): Type
    {
        if ($isCertain) {
            $attributeType = $this->setNestedAttributeAsRequired($attributeType);
        }

        $potentialTypes = $baseType instanceof UnionType ? $baseType->types : array_filter([$baseType]);
        $typesWithAddedAttribute = [];
        $counter = count($potentialTypes);

        for ($i = 0; $i < $counter; $i++) {
            if ($potentialTypes[$i] instanceof ObjectType) {
                $potentialTypes[$i] = clone $potentialTypes[$i];
                $keyString = (string) $key;

                if (isset($potentialTypes[$i]->properties[$keyString])) {
                    if ($isCertain) {
                        $potentialTypes[$i]->properties[$keyString] = $this->applyCertainAttributeMutation(
                            currentType: $potentialTypes[$i]->properties[$keyString],
                            attributeType: $attributeType,
                        );

                    } else {
                        $potentialTypes[$i]->properties[$keyString] = new UnionType([
                            $potentialTypes[$i]->properties[$keyString],
                            $attributeType,
                        ])->unwrapType($this->scope->config);
                    }

                } else {
                    $potentialTypes[$i]->properties[$keyString] = $attributeType->setRequired($isCertain);
                }

                $typesWithAddedAttribute[] = $potentialTypes[$i];

            } else if ($potentialTypes[$i] instanceof ArrayType) {
                $potentialTypes[$i] = clone $potentialTypes[$i];

                if (isset($potentialTypes[$i]->shape[$key])) {
                    if ($isCertain) {
                        $potentialTypes[$i]->shape[$key] = $this->applyCertainAttributeMutation(
                            currentType: $potentialTypes[$i]->shape[$key],
                            attributeType: $attributeType,
                        );

                    } else {
                        $potentialTypes[$i]->shape[$key] = new UnionType([
                            $potentialTypes[$i]->shape[$key],
                            $attributeType,
                        ])->unwrapType($this->scope->config);
                    }

                } else {
                    $potentialTypes[$i]->addItemToArray($key, $attributeType->setRequired($isCertain), $this->scope->config);
                }

                $typesWithAddedAttribute[] = $potentialTypes[$i];
            }
        }

        if ($isCertain) {
            if (empty($typesWithAddedAttribute)) {
                $baseType = new ArrayType;
                $baseType->addItemToArray($key, $attributeType->setRequired(true), $this->scope->config);

            } else {
                $baseType = new UnionType($typesWithAddedAttribute)->unwrapType($this->scope->config);
            }

        } else {
            if (empty($typesWithAddedAttribute)) {
                $arrayType = new ArrayType;
                $arrayType->addItemToArray($key, $attributeType, $this->scope->config);

                $baseType = new UnionType([...$potentialTypes, $arrayType])->unwrapType($this->scope->config);

            } else {
                $baseType = new UnionType($potentialTypes)->unwrapType($this->scope->config);
            }
        }

        return $baseType;
    }


    /**
     * Fall back to class metadata when `max_depth` leaves properties
     * unmaterialized, or nested mutations would be lost.
     */
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


    private function applyCertainAttributeMutation(Type $currentType, Type $attributeType): Type
    {
        if (! $attributeType instanceof ArrayType && ! $attributeType instanceof ObjectType) {
            return $attributeType;
        }

        $currentType = $currentType->unwrapType($this->scope->config);

        if ($currentType instanceof UnionType) {
            $types = [];

            foreach ($currentType->types as $type) {
                $type = $type->unwrapType($this->scope->config);

                if ($type instanceof ArrayType || $type instanceof ObjectType || $type instanceof UnionType) {
                    $types[] = $this->applyCertainAttributeMutation($type, $attributeType);
                }
            }

            if ($types !== []) {
                return new UnionType($types)->unwrapType($this->scope->config);
            }
        }

        $patch = $this->getPatchAttributes($attributeType);

        if ($patch === []) {
            return new UnionType([$currentType, $attributeType])->unwrapType($this->scope->config);
        }

        if ($currentType instanceof ObjectType) {
            $currentType = clone $currentType;

            foreach ($patch as $key => $patchType) {
                $keyString = (string) $key;

                if (isset($currentType->properties[$keyString])) {
                    $currentType->properties[$keyString] = $this->applyCertainAttributeMutation(
                        currentType: $currentType->properties[$keyString],
                        attributeType: $patchType,
                    );

                } else {
                    $currentType->properties[$keyString] = $patchType;
                }
            }

            $currentType->required = $currentType->required || $attributeType->required;

            return $currentType;
        }

        if ($currentType instanceof ArrayType) {
            $currentType = clone $currentType;

            foreach ($patch as $key => $patchType) {
                if (isset($currentType->shape[$key])) {
                    $currentType->shape[$key] = $this->applyCertainAttributeMutation(
                        currentType: $currentType->shape[$key],
                        attributeType: $patchType,
                    );

                } else {
                    $currentType->shape[$key] = $patchType;
                }
            }

            $currentType->required = $currentType->required || $attributeType->required;

            return $currentType;
        }

        return $attributeType;
    }


    /**
     * @return array<int|string, Type>
     */
    private function getPatchAttributes(ArrayType|ObjectType $type): array
    {
        if ($type instanceof ObjectType) {
            return $type->properties;
        }

        return $type->shape;
    }


    private function setNestedAttributeAsRequired(Type $type): Type
    {
        $type = clone $type;

        if ($type instanceof ArrayType) {
            $type->shape = array_map($this->setNestedAttributeAsRequired(...), $type->shape);

            if ($type->itemType !== null) {
                $type->itemType = $this->setNestedAttributeAsRequired($type->itemType);
            }

        } else if ($type instanceof ObjectType) {
            $type->properties = array_map($this->setNestedAttributeAsRequired(...), $type->properties);
        }

        return $type->setRequired(true);
    }
}
