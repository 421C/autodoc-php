<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\Exceptions\AutoDocException;
use PhpParser\Comment;
use PhpParser\Node;
use Throwable;

class PhpArray
{
    public function __construct(
        public Scope $scope,
        public Node\Expr\Array_ $node,
    ) {}

    /**
     * @return array{
     *     shape: array<int|string, Type>,
     *     itemTypes: list<Type>,
     * }
     */
    private function unpackArrayItems(Type $type): array
    {
        $result = [
            'shape' => [],
            'itemTypes' => [],
        ];

        if ($type instanceof UnionType) {
            foreach ($type->types as $typeInUnion) {
                if ($typeInUnion instanceof ArrayType) {
                    if ($typeInUnion->shape) {
                        foreach ($typeInUnion->shape as $key => $valueType) {
                            $result['itemTypes'][] = $valueType;
                            $result['shape'][$key] = $valueType->setRequired(false);
                        }

                    } else {
                        if ($typeInUnion->itemType instanceof UnionType) {
                            $unpackedUnion = $this->unpackArrayItems($typeInUnion->itemType);

                            $result['itemTypes'] = array_merge($result['itemTypes'], $unpackedUnion['itemTypes']);
                            $result['shape'] = array_merge($result['shape'], $unpackedUnion['shape']);

                        } else if ($typeInUnion->itemType) {
                            $result['itemTypes'][] = $typeInUnion->itemType;
                        }
                    }

                } else {
                    $result['itemTypes'][] = $typeInUnion;
                }
            }

        } else if ($type instanceof ArrayType) {
            if ($type->shape) {
                $result['shape'] = $type->shape;

            } else {
                if ($type->itemType instanceof UnionType) {
                    $unpackedUnion = $this->unpackArrayItems($type->itemType);

                    $result['itemTypes'] = array_merge($result['itemTypes'], $unpackedUnion['itemTypes']);
                    $result['shape'] = array_merge($result['shape'], $unpackedUnion['shape']);

                } else if ($type->itemType) {
                    $result['itemTypes'][] = $type->itemType;
                }
            }
        }

        return $result;
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
            $autoIncrementIndex = 0;

            foreach ($this->node->items as $arrayItemNode) {
                if ($arrayItemNode->unpack) {
                    $unpackedArray = $this->unpackArrayItems($this->scope->resolveType($arrayItemNode->value));

                    $itemTypes = array_merge($itemTypes, $unpackedArray['itemTypes']);
                    $arrayType->shape = array_merge($arrayType->shape, $unpackedArray['shape']);

                } else {
                    $comments = $arrayItemNode->getComments();
                    $phpDocDescription = null;
                    $exampleValues = null;
                    $typeFromPhpDoc = null;

                    foreach ($comments as $comment) {
                        if ($comment instanceof Comment\Doc) {
                            $phpDoc = new PhpDoc($comment->getText(), $this->scope);

                            $phpDocDescription = $phpDoc->getText();
                            $exampleValues = $phpDoc->getExampleValues();

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

                    $itemTypes[] = $itemType;

                    if ($arrayItemNode->key instanceof Node\Scalar\Int_) {
                        $arrayType->shape[$arrayItemNode->key->value] = $itemType;
                        $keyTypes[] = new IntegerType($arrayItemNode->key->value);
                        $autoIncrementIndex = max($autoIncrementIndex, $arrayItemNode->key->value + 1);

                    } else if ($arrayItemNode->key instanceof Node\Scalar\String_) {
                        $arrayType->shape[$arrayItemNode->key->value] = $itemType;
                        $keyTypes[] = new StringType($arrayItemNode->key->value);

                    } else if ($resolvePartialArrayShapes && ! $arrayItemNode->key) {
                        $arrayType->shape[$autoIncrementIndex] = $itemType;
                        $keyTypes[] = new IntegerType($autoIncrementIndex);
                        $autoIncrementIndex++;

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
                $arrayType->itemType = (new UnionType($itemTypes))->unwrapType($this->scope->config);

                if ($keyTypes) {
                    $arrayType->keyType = (new UnionType($keyTypes))->unwrapType($this->scope->config);
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
                            $unionType->types[] = new ArrayType(shape: array_merge($arrayType->shape, $variantShape));
                        }
                    }
                }

                return $unionType->unwrapType($this->scope->config);
            }

            if (! $arrayType->shape && ! $resolvePartialArrayShapes) {
                $arrayType->itemType = (new UnionType($itemTypes))->unwrapType($this->scope->config);

                if ($keyTypes) {
                    $arrayType->keyType = (new UnionType($keyTypes))->unwrapType($this->scope->config);
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
