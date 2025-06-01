<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\VoidType;
use Override;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;


class ClassMethodNodeVisitor extends NodeVisitorAbstract
{
    public function __construct(
        private string $methodName,
        private Scope $scope,
        private bool $analyzeReturnValue,
        private bool $isOperationEntrypoint = false,

        /**
         * @var PhpFunctionArgument[]
         */
        private array $args = [],
    ) {}

    /** @var Type[] */
    public array $returnTypes = [];

    public bool $targetMethodExists = false;

    private bool $inTargetMethod = false;

    private int $currentDepth = 0;

    /**
     * @return null|NodeVisitor::*
     */
    #[Override]
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->inTargetMethod = $node->name->toString() === $this->methodName;

            if (! $this->inTargetMethod) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            $this->targetMethodExists = true;

            $docComment = $node->getDocComment();
            $phpDocParameters = [];

            if ($docComment) {
                $phpDoc = new PhpDoc($docComment->getText(), $this->scope);
                $phpDocParameters = $phpDoc->getParameters();
            }

            foreach ($node->params as $paramIndex => $paramNode) {
                if ($paramNode->var instanceof Variable) {
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

        if (! $this->inTargetMethod) {
            return null;
        }

        if ($node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\Class_
        ) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\Switch_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            $this->currentDepth++;
        }

        if ($node instanceof Node\Stmt\Expression) {
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
                        ]);

                        // /** @var {varType} $varName */
                        $this->scope->assignVariable($varNode, $varType, depth: $this->currentDepth);
                    }
                }
            }

            if ($node->expr instanceof Node\Expr\Assign) {
                $this->handleAssignment($node->expr->var, $node->expr->expr, $comments);
            }
        }

        if ($this->analyzeReturnValue && $node instanceof Node\Stmt\Return_) {
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

        if ($node instanceof Node\Expr\MethodCall) {
            $this->scope->handleExpectedRequestTypeFromExtensions($node);
        }

        return null;
    }

    /**
     * @return null
     */
    #[Override]
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->inTargetMethod = false;

        } else if ($node instanceof Node\Stmt\If_ ||
            $node instanceof Node\Stmt\While_ ||
            $node instanceof Node\Stmt\For_ ||
            $node instanceof Node\Stmt\Foreach_ ||
            $node instanceof Node\Stmt\Switch_ ||
            $node instanceof Node\Stmt\TryCatch
        ) {
            $this->currentDepth--;
        }

        return null;
    }


    /**
     * @param Comment[] $comments
     */
    private function handleAssignment(Node $varNode, Node\Expr $valueNode, array $comments = []): void
    {
        if ($varNode instanceof Node\Expr\Variable) {
            // $var = {expr};
            $this->scope->assignVariable($varNode, $valueNode, $comments, depth: $this->currentDepth);

        } else if ($varNode instanceof Node\Expr\ArrayDimFetch) {
            $assignedArrayKey = $this->getRawArrayKeyValue($varNode->dim);

            if ($varNode->var instanceof Node\Expr\Variable) {
                $varType = $this->scope->getVariableType($varNode->var);

                if ($varType) {
                    if ($varType instanceof UnresolvedParserNodeType) {
                        if ($assignedArrayKey) {
                            // $var[$assignedArrayKey] = {expr};
                            $varType->assignedProperties[$assignedArrayKey] = $valueNode;

                        } else {
                            // $var[] = {expr};
                            $varType->assignedProperties[] = $valueNode;
                        }
                    }

                    $this->scope->assignVariable($varNode->var, $varType, depth: $this->currentDepth);
                }

            } else if ($varNode->var instanceof Node\Expr\ArrayDimFetch) {
                $nestedKeys = $this->getNestedArrayAccessKeys($varNode);

                $baseVariable = $nestedKeys['baseVariable'];
                $keyPath = $nestedKeys['keyPath'];

                if ($baseVariable instanceof Node\Expr\Variable) {
                    $varType = $this->scope->getVariableType($baseVariable);

                    if ($varType) {
                        if ($varType instanceof UnresolvedParserNodeType) {
                            $currentLevel = &$varType->assignedProperties;

                            $lastKeyIndex = array_key_last($keyPath);

                            foreach ($keyPath as $keyIndex => $key) {
                                if ($lastKeyIndex !== $keyIndex) {
                                    if ($key === null) {
                                        $placeholder = new Node\Expr\Array_;
                                        $currentLevel[] = new ArrayItem($placeholder);

                                        $currentLevel = &$placeholder->items;

                                    } else {
                                        if (! isset($currentLevel[$key])) {
                                            $currentLevel[$key] = new ArrayItem(new Array_);
                                        }

                                        $currentLevel = &$currentLevel[$key]->value->items;
                                    }

                                    $currentLevel[] = new ArrayItem($valueNode);
                                }
                            }
                        }

                        $this->scope->assignVariable($baseVariable, $varType, depth: $this->currentDepth);
                    }
                }
            }
        }
    }

    /**
     * @return array{
     *     baseVariable: Node\Expr,
     *     keyPath: array<int|string|null>,
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
}
