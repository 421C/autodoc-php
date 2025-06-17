<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\Exceptions\AutoDocException;
use Exception;
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
                        if (! $typeInUnion->itemType) {
                            throw new Exception('Unknown array item type');
                        }

                        if ($typeInUnion->itemType instanceof UnionType) {
                            $unpackedUnion = $this->unpackArrayItems($typeInUnion->itemType);

                            $result['itemTypes'] = array_merge($result['itemTypes'], $unpackedUnion['itemTypes']);
                            $result['shape'] = array_merge($result['shape'], $unpackedUnion['shape']);

                        } else {
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
                if (! $type->itemType) {
                    throw new Exception('Unknown array item type');
                }

                if ($type->itemType instanceof UnionType) {
                    $unpackedUnion = $this->unpackArrayItems($type->itemType);

                    $result['itemTypes'] = array_merge($result['itemTypes'], $unpackedUnion['itemTypes']);
                    $result['shape'] = array_merge($result['shape'], $unpackedUnion['shape']);

                } else {
                    $result['itemTypes'][] = $type->itemType;
                }
            }
        }

        return $result;
    }


    public function resolveType(): ArrayType
    {
        try {
            $arrayType = new ArrayType;
            $itemTypes = [];

            foreach ($this->node->items as $arrayItemNode) {
                if ($arrayItemNode->unpack) {
                    $unpackedArray = $this->unpackArrayItems($this->scope->resolveType($arrayItemNode->value));

                    $itemTypes = array_merge($itemTypes, $unpackedArray['itemTypes']);
                    $arrayType->shape = array_merge($arrayType->shape, $unpackedArray['shape']);

                } else {
                    $comments = $arrayItemNode->getComments();
                    $description = null;
                    $exampleValues = null;
                    $typeFromPhpDoc = null;

                    foreach ($comments as $comment) {
                        if ($comment instanceof Comment\Doc) {
                            $phpDoc = new PhpDoc($comment->getText(), $this->scope);

                            $description = $phpDoc->getText();
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

                    $itemType = new UnresolvedParserNodeType($arrayItemNode->value, $this->scope, $description);

                    if ($typeFromPhpDoc) {
                        $typeFromPhpDoc->description = $itemType->description ?: $description;
                        $typeFromPhpDoc->fallbackType = $itemType;

                        $itemType = $typeFromPhpDoc;
                    }

                    $itemType->examples = $exampleValues ?: $itemType->examples ?: null;
                    $itemType->required = true;

                    $itemTypes[] = $itemType;

                    if ($arrayItemNode->key instanceof Node\Scalar\String_
                        || $arrayItemNode->key instanceof Node\Scalar\Int_
                    ) {
                        $arrayType->shape[$arrayItemNode->key->value] = $itemType;
                    }
                }
            }

            if (! $arrayType->shape) {
                $itemTypesUnion = new UnionType($itemTypes);

                $arrayType->itemType = $itemTypesUnion->unwrapType($this->scope->config);
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
