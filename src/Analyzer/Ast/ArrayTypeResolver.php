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

    public function resolveType(): Type
    {
        try {
            $config = $this->scope->config;

            $arrayType = new ArrayType;

            /** @var array{array<int|string>, Type}[] */
            $keysWithVariants = [];

            /** @var list<array{Type, Type}> */
            $entriesWithoutKnownKey = [];

            $hasAtLeastOneUnknownKey = false;

            $resolvePartialArrayShapes = $config->data['arrays']['resolve_partial_shapes'] ?? false;

            foreach ($this->node->items as $arrayItemNode) {
                if ($arrayItemNode->unpack) {
                    $arrayType->mergeArray($this->scope->resolveType($arrayItemNode->value), $config);

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

                    if ($arrayItemNode->key instanceof Node\Scalar\Int_ || $arrayItemNode->key instanceof Node\Scalar\String_) {
                        $arrayType->shape[$arrayItemNode->key->value] = $itemType;

                    } else if ($resolvePartialArrayShapes && ! $arrayItemNode->key) {
                        $arrayType->mergeArray(new ArrayType(shape: [$itemType]), $config);

                    } else {
                        $keyType = $arrayItemNode->key ? $this->scope->resolveType($arrayItemNode->key) : new IntegerType;

                        [$keyVariants, $mayHaveOtherKeys] = $this->getKeyVariants($keyType);

                        $hasAtLeastOneUnknownKey = $hasAtLeastOneUnknownKey || $mayHaveOtherKeys;

                        if (count($keyVariants) === 1 && ! $mayHaveOtherKeys) {
                            $arrayType->shape[$keyVariants[0]] = $itemType;

                        } else {
                            $entriesWithoutKnownKey[] = [$keyType, $itemType];

                            if ($keyVariants) {
                                $keysWithVariants[] = [$keyVariants, $itemType];
                            }
                        }
                    }
                }
            }

            if ($arrayType->isUntypedOrKnownEmpty() && $entriesWithoutKnownKey === []) {
                return ArrayType::withoutEntries();
            }

            if ($hasAtLeastOneUnknownKey && ! $resolvePartialArrayShapes) {
                return $this->foldWithEntriesWithoutKnownKey($arrayType, $entriesWithoutKnownKey);
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
                            $variantArrayType = clone $arrayType;
                            $variantArrayType->shape = array_replace($arrayType->shape, $variantShape);

                            $unionType->types[] = $variantArrayType;
                        }
                    }
                }

                return $unionType->unwrapType($config);
            }

            return $arrayType;

        } catch (Throwable $exception) {
            if ($this->scope->isDebugModeEnabled()) {
                throw new AutoDocException('Error resolving array type: ', $exception);
            }

            return new ArrayType;
        }
    }


    /**
     * @return array{list<int|string>, bool}
     */
    private function getKeyVariants(Type $keyType): array
    {
        $keyTypes = $keyType instanceof UnionType ? $keyType->types : [$keyType];
        $keyVariants = [];
        $mayHaveOtherKeys = false;

        foreach ($keyTypes as $keyTypeInUnion) {
            $possibleValues = $keyTypeInUnion instanceof StringType || $keyTypeInUnion instanceof IntegerType
                ? $keyTypeInUnion->getPossibleValues()
                : null;

            if ($possibleValues) {
                $keyVariants = array_merge($keyVariants, $possibleValues);

            } else {
                $mayHaveOtherKeys = true;
            }
        }

        return [$keyVariants, $mayHaveOtherKeys];
    }


    /**
     * @param list<array{Type, Type}> $entriesWithoutKnownKey
     */
    private function foldWithEntriesWithoutKnownKey(ArrayType $arrayType, array $entriesWithoutKnownKey): ArrayType
    {
        $config = $this->scope->config;
        $arrayType->convertShapeToTypePair($config);

        $keyTypes = array_column($entriesWithoutKnownKey, 0);
        $itemTypes = array_column($entriesWithoutKnownKey, 1);

        if ($arrayType->hasEntriesOutsideShape()) {
            array_unshift($keyTypes, $arrayType->keyType ?? new IntegerType);
            array_unshift($itemTypes, $arrayType->itemType ?? new UnknownType);
        }

        $arrayType->keyType = new UnionType($keyTypes)->unwrapType($config);
        $arrayType->itemType = new UnionType($itemTypes)->unwrapType($config);

        return $arrayType;
    }
}
