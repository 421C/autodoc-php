<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\NullType;
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
     * Merge attributes into `$baseType`, optionally at `$mutationPath`.
     * Mutations to a shared `itemType` stay certain only when `$readPath`
     * targets the same element.
     *
     * @param list<int|string|null> $mutationPath
     * @param array<int|string, Type> $attributes
     * @param list<int|string> $readPath
     */
    public function apply(
        ?Type $baseType,
        array $mutationPath,
        array $attributes,
        bool $isCertain,
        array $readPath = [],
        ?Type $dynamicAttribute = null,
    ): ?Type {
        if ($attributes === [] && $dynamicAttribute === null) {
            return $baseType;
        }

        if ($baseType === null) {
            $baseType = new ArrayType;

        } else {
            $baseType = $baseType->unwrapType($this->scope->config);
        }

        if ($baseType instanceof NullType) {
            $mutatedArrayType = $this->apply(
                baseType: new ArrayType,
                mutationPath: $mutationPath,
                attributes: $attributes,
                isCertain: true,
                readPath: $readPath,
                dynamicAttribute: $dynamicAttribute,
            );

            if ($mutatedArrayType === null) {
                return $baseType;
            }

            if ($isCertain) {
                return $mutatedArrayType;
            }

            return new UnionType([$mutatedArrayType, $baseType])->unwrapType($this->scope->config);
        }

        if ($mutationPath === [] && $dynamicAttribute === null) {
            foreach ($attributes as $key => $attributeType) {
                $baseType = $this->mergeAttribute($baseType, $key, $attributeType, $isCertain, $readPath);
            }

            return $baseType;
        }

        if ($baseType instanceof UnionType) {
            $baseType = clone $baseType;

            $baseType->types = array_map(
                fn (Type $typeVariant): Type => $this->apply(
                    baseType: $typeVariant,
                    mutationPath: $mutationPath,
                    attributes: $attributes,
                    isCertain: $isCertain,
                    readPath: $readPath,
                    dynamicAttribute: $dynamicAttribute,
                ) ?? $typeVariant,
                $baseType->types,
            );

            return $baseType->unwrapType($this->scope->config);
        }

        if ($mutationPath === []) {
            foreach ($attributes as $key => $attributeType) {
                $baseType = $this->mergeAttribute($baseType, $key, $attributeType, $isCertain, $readPath);
            }

            $baseType = $this->mergeDynamicAttribute($baseType, $dynamicAttribute, $isCertain);

            return $baseType;
        }

        $pathKey = $mutationPath[0];
        $remainingPath = array_slice($mutationPath, 1);

        if ($pathKey === null) {
            return $this->applyAtDynamicPath(
                baseType: $baseType,
                remainingPath: $remainingPath,
                attributes: $attributes,
                isCertain: $isCertain,
                dynamicAttribute: $dynamicAttribute,
            );
        }

        $readPathIsAligned = ($readPath[0] ?? null) === $pathKey;
        $readPathTail = $readPathIsAligned ? array_slice($readPath, 1) : [];

        if ($baseType instanceof ObjectType) {
            $propertyType = $this->scope->getObjectPropertyType($baseType, $pathKey);

            if ($propertyType === null) {
                $createdPropertyType = $this->apply(
                    baseType: new ArrayType,
                    mutationPath: $remainingPath,
                    attributes: $attributes,
                    isCertain: $isCertain,
                    readPath: $readPathTail,
                    dynamicAttribute: $dynamicAttribute,
                );

                if ($createdPropertyType === null) {
                    return $baseType;
                }

                $baseType = clone $baseType;
                $baseType->properties[(string) $pathKey] = $createdPropertyType->setRequired($isCertain);

                return $baseType;
            }

            $wasRequired = $propertyType->required;

            $mergedPropertyType = $this->apply(
                baseType: $propertyType,
                mutationPath: $remainingPath,
                attributes: $attributes,
                isCertain: $isCertain,
                readPath: $readPathTail,
                dynamicAttribute: $dynamicAttribute,
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
                $createdElementType = $this->apply(
                    baseType: new ArrayType,
                    mutationPath: $remainingPath,
                    attributes: $attributes,
                    isCertain: $isCertain,
                    readPath: $readPathTail,
                    dynamicAttribute: $dynamicAttribute,
                );

                if ($createdElementType === null) {
                    return $baseType;
                }

                $baseType = clone $baseType;
                $baseType->addItemToArray($pathKey, $createdElementType->setRequired($isCertain), $this->scope->config);

                return $baseType;
            }

            $isSharedItemType = ! isset($baseType->shape[$pathKey]);
            $wasRequired = $elementType->required;

            $mergedElementType = $this->apply(
                baseType: $elementType->unwrapType($this->scope->config),
                mutationPath: $remainingPath,
                attributes: $attributes,
                isCertain: $isCertain && (! $isSharedItemType || $readPathIsAligned),
                readPath: $readPathTail,
                dynamicAttribute: $dynamicAttribute,
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


    /**
     * @param list<int|string|null> $remainingPath
     * @param array<int|string, Type> $attributes
     */
    private function applyAtDynamicPath(
        Type $baseType,
        array $remainingPath,
        array $attributes,
        bool $isCertain,
        ?Type $dynamicAttribute,
    ): Type {
        if ($baseType instanceof ArrayType || $baseType instanceof ObjectType) {
            $baseType = $this->mapElements($baseType, fn (Type $elementType): ?Type => $this->apply(
                baseType: $elementType,
                mutationPath: $remainingPath,
                attributes: $attributes,
                isCertain: false,
                dynamicAttribute: $dynamicAttribute,
            ));

            if ($baseType instanceof ArrayType && $baseType->shape === [] && $baseType->itemType === null) {
                $createdElementType = $this->apply(
                    baseType: null,
                    mutationPath: $remainingPath,
                    attributes: $attributes,
                    isCertain: true,
                    dynamicAttribute: $dynamicAttribute,
                );

                if ($createdElementType !== null) {
                    $baseType->addItemToArray(null, $createdElementType->setRequired($isCertain), $this->scope->config);
                }
            }

            return $baseType;
        }

        $createdType = $this->apply(
            baseType: null,
            mutationPath: $remainingPath,
            attributes: $attributes,
            isCertain: true,
            dynamicAttribute: $dynamicAttribute,
        );

        if ($createdType === null || $isCertain) {
            return $createdType ?? $baseType;
        }

        return new UnionType([$baseType, $createdType])->unwrapType($this->scope->config);
    }


    private function mergeDynamicAttribute(Type $baseType, Type $attributeType, bool $isCertain): Type
    {
        $attributeType = clone $attributeType;

        if ($baseType instanceof ArrayType || $baseType instanceof ObjectType) {
            $baseType = $this->mapElements($baseType, fn (Type $elementType): Type => new UnionType([
                $elementType,
                $attributeType,
            ])->unwrapType($this->scope->config));

            if ($baseType instanceof ArrayType && $baseType->shape === [] && $baseType->itemType === null) {
                $baseType->addItemToArray(null, $attributeType->setRequired($isCertain), $this->scope->config);
            }

            return $baseType;
        }

        $arrayType = new ArrayType;
        $arrayType->addItemToArray(null, $attributeType->setRequired($isCertain), $this->scope->config);

        if ($isCertain) {
            return $arrayType;
        }

        return new UnionType([$baseType, $arrayType])->unwrapType($this->scope->config);
    }


    /**
     * Maps every element of the container through `$mapElement`, keeping each
     * element's required flag. A `null` mapping keeps the element unchanged.
     *
     * @param callable(Type): ?Type $mapElement
     */
    private function mapElements(ArrayType|ObjectType $baseType, callable $mapElement): ArrayType|ObjectType
    {
        $baseType = clone $baseType;

        if ($baseType instanceof ObjectType) {
            foreach ($baseType->properties as $key => $propertyType) {
                $baseType->properties[$key] = $this->mapElementPreservingRequired($propertyType, $mapElement);
            }

            return $baseType;
        }

        foreach ($baseType->shape as $key => $elementType) {
            $baseType->shape[$key] = $this->mapElementPreservingRequired($elementType, $mapElement);
        }

        if ($baseType->itemType !== null) {
            $baseType->itemType = $this->mapElementPreservingRequired($baseType->itemType, $mapElement);
        }

        return $baseType;
    }


    /**
     * @param callable(Type): ?Type $mapElement
     */
    private function mapElementPreservingRequired(Type $elementType, callable $mapElement): Type
    {
        $wasRequired = $elementType->required;
        $mappedType = $mapElement($elementType);

        if ($mappedType === null) {
            return $elementType;
        }

        return $mappedType->setRequired($wasRequired);
    }


    /**
     * @param list<int|string> $readPath
     */
    private function mergeAttribute(?Type $baseType, int|string $key, Type $attributeType, bool $isCertain, array $readPath = []): Type
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

                } else if ($isCertain && ($readPath[0] ?? null) === $key) {
                    // The shape entry shadows the shared itemType only for this exact-read
                    // resolution, consumed immediately via getTypeAtKey.
                    $potentialTypes[$i]->shape[$key] = $attributeType;

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
