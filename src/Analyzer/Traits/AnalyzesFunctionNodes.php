<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Traits;

use AutoDoc\Analyzer\PhpCondition;
use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\VoidType;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;


trait AnalyzesFunctionNodes
{
    /**
     * @var PhpCondition[]
     */
    private array $conditionStack = [];

    private int $currentConditionScopeIndex = 0;


    /**
     * @param Node\Param[] $params
     */
    private function handleParameters(array $params, ?Comment $docComment = null): void
    {
        $phpDocParameters = [];

        if ($docComment) {
            $phpDoc = new PhpDoc($docComment->getText(), $this->scope);
            $phpDocParameters = $phpDoc->getParameters();
        }

        foreach ($params as $paramIndex => $paramNode) {
            if ($paramNode->var instanceof Variable) {
                $paramNode->var->setAttribute('startLine', $paramNode->var->getStartLine() - 1);

                if (is_string($paramNode->var->name) && isset($phpDocParameters[$paramNode->var->name])) {
                    $this->scope->assignVariable($paramNode->var, $phpDocParameters[$paramNode->var->name], $docComment ? [$docComment] : []);

                } else if (isset($this->args[$paramIndex])) {
                    $this->scope->assignVariable($paramNode->var, $this->args[$paramIndex]->getType() ?? new UnknownType, $docComment ? [$docComment] : []);

                } else if (isset($paramNode->type)) {
                    $this->scope->assignVariable($paramNode->var, $paramNode->type, $docComment ? [$docComment] : []);
                }

                if ($paramNode->type instanceof Node\Name) {
                    $className = $this->scope->getResolvedClassName($paramNode->type);

                    if ($className) {
                        $phpClass = $this->scope->getPhpClassInDeeperScope($className);

                        if ($phpClass->exists()) {
                            $this->scope->handleExpectedRequestTypeFromExtensions($phpClass);
                        }
                    }
                }
            }
        }
    }

    private function handleExpression(Node\Stmt\Expression $node): void
    {
        $comments = $node->getComments();

        foreach ($comments as $comment) {
            if ($comment instanceof Comment\Doc) {
                $phpDoc = new PhpDoc($comment->getText(), $this->scope);

                foreach ($phpDoc->getVarTags() as $var) {
                    [$varName, $varType] = $var;

                    if (! $varName) {
                        continue;
                    }

                    $varNode = new Variable($varName, [
                        'startLine' => $comment->getStartLine(),
                        'endLine' => $comment->getEndLine(),
                        'startFilePos' => $comment->getStartFilePos(),
                        'endFilePos' => $comment->getEndFilePos(),
                    ]);

                    // /** @var {varType} $varName */
                    $this->scope->assignVariable(
                        varNode: $varNode,
                        valueNode: $varType,
                        depth: $this->currentDepth,
                        conditions: $this->conditionStack,
                    );
                }
            }
        }

        if ($node->expr instanceof Node\Expr\Assign) {
            $this->handleAssignment($node->expr->var, $node->expr->expr, $comments);
        }

        if ($this->isOperationEntrypoint && $node->expr instanceof Node\Expr\Throw_) {
            $responseType = $this->scope->handleThrowExtensions($node->expr->expr);

            if ($responseType !== null) {
                $this->returnTypes[] = $responseType;
            }
        }
    }

    private function handleReturnStatement(Node\Stmt\Return_ $node): void
    {
        if ($node->expr) {
            // return {expr};
            $this->returnTypes[] = new UnresolvedParserNodeType(
                node: $node->expr,
                scope: $this->scope,
                isFinalResponse: $this->isOperationEntrypoint,
            );

        } else {
            // return;
            $this->returnTypes[] = new VoidType;
        }
    }

    /**
     * @param Comment[] $comments
     */
    private function handleAssignment(Node $varNode, Node\Expr $valueNode, array $comments = []): void
    {
        if ($varNode instanceof Node\Expr\Variable) {
            // $var = {expr}
            $this->scope->assignVariable(
                varNode: $varNode,
                valueNode: $valueNode,
                comments: $comments,
                depth: $this->currentDepth,
                conditions: $this->conditionStack,
            );

        } else if ($varNode instanceof Node\Expr\ArrayDimFetch) {
            $assignedArrayKey = $this->getRawArrayKeyValue($varNode->dim);

            if ($assignedArrayKey === null) {
                // {varNode->var}[]  ->  {varNode->var}[0]
                $assignedArrayKey = 0;
            }

            if ($varNode->var instanceof Node\Expr\Variable) {
                // {varNode->var}[$assignedArrayKey] = {valueNode}
                $this->scope->mutateVariable(
                    varNode: $varNode->var,
                    changes: [
                        'attributes' => [
                            $assignedArrayKey => new UnresolvedParserNodeType(node: $valueNode, scope: $this->scope),
                        ],
                    ],
                    depth: $this->currentDepth,
                    conditions: $this->conditionStack,
                );

            } else if ($varNode->var instanceof Node\Expr\ArrayDimFetch) {
                $nestedKeys = $this->getNestedArrayAccessKeys($varNode);

                $baseVariable = $nestedKeys['baseVariable'];
                $keyPath = $nestedKeys['keyPath'];

                if ($baseVariable instanceof Node\Expr\Variable) {
                    $attributes = [];
                    $lastKeyIndex = array_key_last($keyPath);
                    $currentLevel = &$attributes;

                    foreach ($keyPath as $keyIndex => $key) {
                        if ($keyIndex === $lastKeyIndex) {
                            if ($key === null) {
                                // {baseVariable}[...][] = {valueNode}
                                $currentLevel[] = new UnresolvedParserNodeType(node: $valueNode, scope: $this->scope);

                            } else {
                                // {baseVariable}[...][$key] = {valueNode}
                                $currentLevel[$key] = new UnresolvedParserNodeType(node: $valueNode, scope: $this->scope);
                            }

                        } else if ($key === null) {
                            // {baseVariable}[...][][...]
                            //                     ↪ ↑
                            $arrayType = new ArrayType;
                            $currentLevel[] = $arrayType;

                            $currentLevel = &$arrayType->shape;

                        } else {
                            // {baseVariable}[...][$key][...]
                            //                         ↪ ↑
                            if (! isset($currentLevel[$key])) {
                                $currentLevel[$key] = new ArrayType;
                            }

                            $currentLevel = &$currentLevel[$key]->shape;
                        }
                    }

                    $attributes = $this->normalizeNestedArrayTypes($attributes);

                    $this->scope->mutateVariable(
                        varNode: $baseVariable,
                        changes: ['attributes' => $attributes],
                        depth: $this->currentDepth,
                        conditions: $this->conditionStack,
                    );
                }
            }
        }
    }

    /**
     * @param array<int|string, Type> $attributes
     * @return array<int|string, Type>
     */
    private function normalizeNestedArrayTypes(array $attributes): array
    {
        foreach ($attributes as $attributeKey => $attributeType) {
            if ($attributeType instanceof ArrayType) {
                if ($attributeType->shape) {
                    $attributeType->shape = $this->normalizeNestedArrayTypes($attributeType->shape);

                    $hasIntegerKeys = false;

                    foreach (array_keys($attributeType->shape) as $shapeKey) {
                        if (is_int($shapeKey)) {
                            $hasIntegerKeys = true;
                            break;
                        }
                    }

                    if ($hasIntegerKeys) {
                        $attributeType->convertShapeToTypePair($this->scope->config);
                    }
                }
            }

            $attributes[$attributeKey] = $attributeType;
        }

        return $attributes;
    }

    /**
     * @return array{
     *     baseVariable: Node\Expr,
     *     keyPath: list<int|string|null>,
     * }
     */
    private function getNestedArrayAccessKeys(Node\Expr\ArrayDimFetch $arrayAccessNode): array
    {
        $keyPath = [];
        $currentNode = $arrayAccessNode;

        while ($currentNode instanceof Node\Expr\ArrayDimFetch) {
            $keyPath[] = $this->getRawArrayKeyValue($currentNode->dim);
            $currentNode = $currentNode->var;
        }

        return [
            'baseVariable' => $currentNode,
            'keyPath' => array_reverse($keyPath),
        ];
    }


    private function getRawArrayKeyValue(?Node $node): int|string|null
    {
        if (! $node) {
            return null;
        }

        $arrayKey = $this->scope->getRawValueFromNode($node);

        if (is_float($arrayKey)) {
            return null;
        }

        return $arrayKey;
    }


    protected function handleConditionNode(Node $node): bool
    {
        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Switch_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            $this->conditionStack[] = new PhpCondition($node);

            return true;
        }

        return false;
    }

    protected function handleConditionEnd(Node $node): bool
    {
        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Switch_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            array_pop($this->conditionStack);

            return true;
        }

        return false;
    }
}
