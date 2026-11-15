<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Ast;

use AutoDoc\Analyzer\DocBlock\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\Exceptions\AutoDocException;
use PhpParser\Comment;
use PhpParser\Node;
use Throwable;

class ArrayTypeResolver
{
    public function __construct(
        public Scope $scope,
        public Node\Expr\Array_ $node,
    ) {}

    /**
     * @return array{
     *     shape: array<int|string, Type>,
     *     isTuple: bool,
     *     itemTypes: list<Type>,
     *     keyTypes: list<Type>,
     *     hasUnknownKeys: bool,
     * }
     */
    private function unpackArrayItems(Type $type): array
    {
        if ($type instanceof UnionType) {
            return $this->unpackUnionItems($type);
        }

        if ($type instanceof ArrayType) {
            if ($type->shape) {
                return [
                    'shape' => $type->shape,
                    'isTuple' => $type->isTuple(),
                    'itemTypes' => array_values($type->shape),
                    'keyTypes' => $this->getShapeKeyTypes($type->shape),
                    'hasUnknownKeys' => false,
                ];
            }

            return [
                'shape' => [],
                'isTuple' => false,
                'itemTypes' => $this->getListItemTypes($type),
                'keyTypes' => $this->getPairKeyTypes($type),
                'hasUnknownKeys' => $this->isTypedPair($type),
            ];
        }

        return [
            'shape' => [],
            'isTuple' => false,
            'itemTypes' => [new UnknownType],
            'keyTypes' => [],
            'hasUnknownKeys' => false,
        ];
    }


    /**
     * @return array{
     *     shape: array<int|string, Type>,
     *     isTuple: bool,
     *     itemTypes: list<Type>,
     *     keyTypes: list<Type>,
     *     hasUnknownKeys: bool,
     * }
     */
    private function unpackUnionItems(UnionType $type): array
    {
        $itemTypes = [];
        $keyTypes = [];
        $isTuple = true;
        $hasUnknownKeys = false;
        $valueTypesByKey = [];

        foreach ($type->types as $typeInUnion) {
            if ($typeInUnion instanceof ArrayType && $typeInUnion->shape) {
                foreach ($typeInUnion->shape as $key => $valueType) {
                    $itemTypes[] = $valueType;
                    $valueTypesByKey[$key][] = $valueType;
                }

                $keyTypes = array_merge($keyTypes, $this->getShapeKeyTypes($typeInUnion->shape));
                $isTuple = $isTuple && $typeInUnion->isTuple();

            } else if ($typeInUnion instanceof ArrayType) {
                $itemTypes = array_merge($itemTypes, $this->getListItemTypes($typeInUnion));
                $keyTypes = array_merge($keyTypes, $this->getPairKeyTypes($typeInUnion));
                $isTuple = false;
                $hasUnknownKeys = $hasUnknownKeys || $this->isTypedPair($typeInUnion);

            } else {
                $itemTypes[] = $typeInUnion;
                $isTuple = false;
            }
        }

        $shape = [];

        foreach ($valueTypesByKey as $key => $valueTypes) {
            $isInEveryMember = count($valueTypes) === count($type->types);
            $isRequired = $isInEveryMember && array_all($valueTypes, fn (Type $valueType) => $valueType->required);

            $shape[$key] = count($valueTypes) === 1
                ? (clone $valueTypes[0])->setRequired($isRequired)
                : new UnionType($valueTypes)->setRequired($isRequired);
        }

        return [
            'shape' => $shape,
            'isTuple' => $isTuple,
            'itemTypes' => $itemTypes,
            'keyTypes' => $keyTypes,
            'hasUnknownKeys' => $hasUnknownKeys,
        ];
    }


    /**
     * @return list<Type>
     */
    private function getListItemTypes(ArrayType $type): array
    {
        if ($type->itemType instanceof UnionType) {
            return array_values($type->itemType->types);
        }

        return [$type->itemType ?? new UnknownType];
    }


    /**
     * @param array<int|string, Type> $shape
     * @return list<Type>
     */
    private function getShapeKeyTypes(array $shape): array
    {
        return array_map(
            callback: fn (int|string $key): Type => is_int($key) ? new IntegerType : new StringType($key),
            array: array_keys($shape),
        );
    }


    /**
     * @return list<Type>
     */
    private function getPairKeyTypes(ArrayType $type): array
    {
        if (! $this->isTypedPair($type)) {
            return [];
        }

        return [$type->getRenumberedKeyType($this->scope->config) ?? new IntegerType];
    }


    private function isTypedPair(ArrayType $type): bool
    {
        return $type->itemType !== null || $type->keyType !== null;
    }


    public function resolveType(): Type
    {
        try {
            $arrayType = new ArrayType;
            $keyTypes = [];
            $itemTypes = [];

            if (! $this->node->items) {
                return $arrayType;
            }

            /** @var array{array<int|string>, Type}[] */
            $keysWithVariants = [];

            $hasAtLeastOneUnknownKey = false;

            $resolvePartialArrayShapes = $this->scope->config->data['arrays']['resolve_partial_shapes'] ?? false;

            foreach ($this->node->items as $arrayItemNode) {
                if ($arrayItemNode->unpack) {
                    $unpackedArray = $this->unpackArrayItems($this->scope->resolveType($arrayItemNode->value));

                    $itemTypes = array_merge($itemTypes, $unpackedArray['itemTypes']);
                    $keyTypes = array_merge($keyTypes, $unpackedArray['keyTypes']);
                    $hasAtLeastOneUnknownKey = $hasAtLeastOneUnknownKey || $unpackedArray['hasUnknownKeys'];
                    $arrayType->mergeShape($unpackedArray['shape'], isTuple: $unpackedArray['isTuple']);

                } else {
                    $comments = $arrayItemNode->getComments();
                    $phpDocDescription = null;
                    $deprecations = [];
                    $exampleValues = null;
                    $typeFromPhpDoc = null;

                    foreach ($comments as $comment) {
                        if ($comment instanceof Comment\Doc) {
                            $phpDoc = new PhpDoc($comment->getText(), $this->scope);

                            if ($phpDoc->getAutodocIgnoreTag()) {
                                continue 2;
                            }

                            $phpDocDescription = $phpDoc->getText();
                            $exampleValues = $phpDoc->getExampleValues();
                            $deprecations = array_merge($deprecations, $phpDoc->getDeprecatedTags());

                            foreach ($phpDoc->getVarTags() as $var) {
                                [$varName, $varType] = $var;

                                if (! $varName) {
                                    $typeFromPhpDoc = $varType;
                                    break;
                                }
                            }
                        }
                    }

                    $itemType = new UnresolvedParserNodeType($arrayItemNode->value, $this->scope, $phpDocDescription);

                    if ($typeFromPhpDoc) {
                        $typeFromPhpDoc->addDescription($itemType->description, prepend: true);
                        $typeFromPhpDoc->fallbackType = $itemType;

                        $itemType = $typeFromPhpDoc;
                    }

                    $itemType->examples = $exampleValues ?: $itemType->examples ?: null;
                    $itemType->required = true;
                    $itemType->deprecated = (bool) $deprecations;

                    foreach ($deprecations as $deprecation) {
                        $itemType->addDeprecatedDescription($deprecation['description']);
                    }

                    $itemTypes[] = $itemType;

                    if ($arrayItemNode->key instanceof Node\Scalar\Int_) {
                        $arrayType->shape[$arrayItemNode->key->value] = $itemType;
                        $keyTypes[] = new IntegerType($arrayItemNode->key->value);

                    } else if ($arrayItemNode->key instanceof Node\Scalar\String_) {
                        $arrayType->shape[$arrayItemNode->key->value] = $itemType;
                        $keyTypes[] = new StringType($arrayItemNode->key->value);

                    } else if ($resolvePartialArrayShapes && ! $arrayItemNode->key) {
                        $arrayType->mergeShape([$itemType]);

                    } else {
                        $keyType = $arrayItemNode->key ? $this->scope->resolveType($arrayItemNode->key) : new IntegerType;
                        $keyTypes[] = $keyType;

                        if ($keyType instanceof StringType || $keyType instanceof IntegerType) {
                            $keyVariants = $keyType->getPossibleValues() ?? [];

                            if (count($keyVariants) === 1) {
                                $arrayType->shape[$keyVariants[0]] = $itemType;

                            } else if ($keyVariants) {
                                $keysWithVariants[] = [$keyVariants, $itemType];

                            } else {
                                $hasAtLeastOneUnknownKey = true;
                            }

                        } else if ($keyType instanceof UnionType) {
                            $keyVariants = [];

                            foreach ($keyType->types as $keyType) {
                                if ($keyType instanceof StringType || $keyType instanceof IntegerType) {
                                    $possibleValues = $keyType->getPossibleValues();

                                    if ($possibleValues) {
                                        $keyVariants = array_merge($keyVariants, $possibleValues);

                                    } else {
                                        $hasAtLeastOneUnknownKey = true;
                                    }
                                }
                            }

                            $keysWithVariants[] = [$keyVariants, $itemType];
                        }
                    }
                }
            }

            if ($hasAtLeastOneUnknownKey && !$resolvePartialArrayShapes) {
                $arrayType->itemType = new UnionType($itemTypes)->unwrapType($this->scope->config);

                if ($keyTypes) {
                    $arrayType->keyType = new UnionType($keyTypes)->unwrapType($this->scope->config);
                }

                $arrayType->shape = [];

                return $arrayType;
            }

            if ($keysWithVariants) {
                $unionType = new UnionType;

                foreach ($keysWithVariants as $keyIndex => $keyWithVariants) {
                    [$keyVariants, $itemType] = $keyWithVariants;

                    foreach ($keyVariants as $keyVariant) {
                        $variantShapes = [];

                        foreach ($keysWithVariants as $otherKeyIndex => $otherKeyWithVariants) {
                            if ($keyIndex !== $otherKeyIndex) {
                                [$otherKeyVariants, $otherItemType] = $otherKeyWithVariants;

                                foreach ($otherKeyVariants as $otherKeyVariant) {
                                    $variantShapes[] = [
                                        $keyVariant => $itemType,
                                        $otherKeyVariant => $otherItemType,
                                    ];
                                }
                            }
                        }

                        if (! $variantShapes) {
                            $variantShapes[] = [
                                $keyVariant => $itemType,
                            ];
                        }

                        foreach ($variantShapes as $variantShape) {
                            $unionType->types[] = new ArrayType(shape: array_replace($arrayType->shape, $variantShape));
                        }
                    }
                }

                return $unionType->unwrapType($this->scope->config);
            }

            if (! $arrayType->shape && ! $resolvePartialArrayShapes) {
                $arrayType->itemType = new UnionType($itemTypes)->unwrapType($this->scope->config);

                if ($keyTypes) {
                    $arrayType->keyType = new UnionType($keyTypes)->unwrapType($this->scope->config);
                }
            }

            return $arrayType;

        } catch (Throwable $exception) {
            if ($this->scope->isDebugModeEnabled()) {
                throw new AutoDocException('Error resolving array type: ', $exception);
            }

            return new ArrayType;
        }
    }
}
