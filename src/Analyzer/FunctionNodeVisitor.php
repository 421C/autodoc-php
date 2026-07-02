<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedArrayItemType;
use AutoDoc\DataTypes\UnresolvedArrayKeyType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\VoidType;
use Override;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class FunctionNodeVisitor extends NodeVisitorAbstract
{
    public function __construct(
        protected Scope $scope,
        private readonly bool $analyzeReturnValue,
        private readonly ArgumentList $args,
        private readonly bool $isOperationEntrypoint = false,
        private readonly ?string $methodName = null,
        private readonly ?Scope $parentScope = null,
    ) {}

    /** @var Type[] */
    public array $returnTypes = [];

    public bool $bodyBreaksOut = false;

    public bool $targetMethodExists = false;

    private bool $inTargetMethod = false;

    /** @var Comment[] */
    private array $currentExpressionComments = [];

    /**
     * Tracks the active PhpCondition for branch index management.
     *
     * @var PhpCondition[]
     */
    private array $conditionStack = [];

    /**
     * Tracks the current branch index within each condition.
     *
     * @var int[]
     */
    private array $branchIndexStack = [];

    /**
     * @param Node[] $nodes
     * @return Node[]
     */
    #[Override]
    public function beforeTraverse(array $nodes): array
    {
        if ($this->methodName !== null) {
            return $nodes;
        }

        $node = $nodes[0];

        if ($node instanceof Node\Expr\Closure) {
            $this->handleParameters($node->params, $node->getDocComment());

            if (! empty($node->uses)) {
                $usedVarNames = [];

                foreach ($node->uses as $useNode) {
                    if (is_string($useNode->var->name)) {
                        $usedVarNames[] = $useNode->var->name;
                    }
                }

                $this->scope->transferVariablesFrom($this->parentScope ?? $this->scope, $usedVarNames);
            }

            $this->bodyBreaksOut = new BranchBreakout($this->scope)->getBreakOutNodeFromStatements($node->stmts) !== null;

            return $node->stmts;
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            $this->handleParameters($node->params, $node->getDocComment());
            $this->scope->transferVariablesFrom($this->parentScope ?? $this->scope);

            $this->scope->className = ($this->parentScope ?? $this->scope)->className;
            $this->bodyBreaksOut = new BranchBreakout($this->scope)->statementBreaksOut(new Node\Stmt\Expression($node->expr));

            $this->returnTypes = [
                new UnresolvedParserNodeType(
                    node: $node->expr,
                    scope: $this->scope,
                ),
            ];

            return [];
        }

        if ($node instanceof Node\Stmt\Function_) {
            $this->handleParameters($node->params, $node->getDocComment());
            $this->bodyBreaksOut = new BranchBreakout($this->scope)->getBreakOutNodeFromStatements($node->stmts) !== null;

            return $node->stmts;
        }

        return [];
    }


    /**
     * @return null|NodeVisitor::*
     */
    #[Override]
    public function enterNode(Node $node)
    {
        if ($this->methodName !== null) {
            if ($node instanceof Node\Stmt\ClassMethod) {
                $this->inTargetMethod = strcasecmp($node->name->toString(), $this->methodName) === 0;

                if (! $this->inTargetMethod) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                $this->targetMethodExists = true;

                $this->handleParameters($node->params, $node->getDocComment());
                $this->bodyBreaksOut = new BranchBreakout($this->scope)->getBreakOutNodeFromStatements($node->stmts ?? []) !== null;
            }

            if (! $this->inTargetMethod) {
                return null;
            }
        }

        if ($node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\Class_
        ) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Node\Stmt\Expression) {
            $this->currentExpressionComments = $node->getComments();

            return null;
        }

        $comments = array_merge($this->currentExpressionComments, $node->getComments());

        $this->handleComments($comments);

        $this->handleExpression($node, $comments);

        if ($node instanceof Node\Stmt\Foreach_) {
            $this->handleForeach($node);
        }

        $this->handleBranchEnter($node);

        if ($this->analyzeReturnValue && $node instanceof Node\Stmt\Return_) {
            $this->handleReturnStatement($node);
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $this->scope->handleExpectedRequestTypeFromExtensions(new MethodCallContext(node: $node, scope: $this->scope));
        }

        return null;
    }


    /**
     * @return null
     */
    #[Override]
    public function leaveNode(Node $node)
    {
        if ($this->methodName !== null && $node instanceof Node\Stmt\ClassMethod) {
            $this->inTargetMethod = false;
        }

        if ($node instanceof Node\Stmt\Expression) {
            $this->currentExpressionComments = [];
        }

        $this->handleBranchExit($node);

        return null;
    }


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

                $paramName = is_string($paramNode->var->name) ? $paramNode->var->name : null;
                $argIndex = $paramName !== null ? $this->args->indexForParameter($paramName, $paramIndex) : null;

                if ($paramName !== null && isset($phpDocParameters[$paramName])) {
                    $this->scope->assignVariable($paramNode->var, $phpDocParameters[$paramName], $docComment ? [$docComment] : []);

                } else if ($argIndex !== null) {
                    $this->scope->assignVariable($paramNode->var, $this->args->get($argIndex, autoResolve: false), $docComment ? [$docComment] : []);

                } else if (! $this->isOperationEntrypoint && $paramNode->default !== null) {
                    // A skipped argument takes its default; an entrypoint's params
                    // come from the framework, so they keep their declared type.
                    $this->scope->assignVariable($paramNode->var, $paramNode->default, $docComment ? [$docComment] : []);

                } else if (isset($paramNode->type)) {
                    $this->scope->assignVariable($paramNode->var, $paramNode->type, $docComment ? [$docComment] : []);
                }

                if ($paramNode->type instanceof Node\Name) {
                    $className = $this->scope->getResolvedClassName($paramNode->type);

                    if ($className) {
                        $phpClass = $this->scope->getPhpClassInDeeperScope($className);

                        if ($phpClass->exists()) {
                            $this->scope->handleExpectedRequestTypeFromClassExtensions($phpClass);
                        }
                    }
                }
            }
        }
    }


    /**
     * @param Comment[] $comments
     */
    private function handleComments(array $comments): void
    {
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

                    $this->scope->assignVariable(
                        varNode: $varNode,
                        valueNode: $varType,
                    );
                }
            }
        }
    }


    /**
     * @param Comment[] $comments
     */
    private function handleExpression(Node $node, array $comments): void
    {
        if ($node instanceof Node\Expr\Assign) {
            $this->handleAssignment($node->var, $node->expr, $comments);
        }

        // Compound assignments (`.=`, `+=`, `??=`, …) update the variable to the
        // operation's result type. The whole node is stored so its endFilePos spans
        // the expression: `??=` re-reads the operand `$x`, and that self-referential
        // read (excluded by readFilePos <= endFilePos) then resolves to the
        // pre-assignment type instead of looping on the event being created.
        if ($node instanceof Node\Expr\AssignOp) {
            $this->handleAssignment($node->var, $node, $comments);
        }

        if ($node instanceof Node\Expr\PostInc
            || $node instanceof Node\Expr\PostDec
            || $node instanceof Node\Expr\PreInc
            || $node instanceof Node\Expr\PreDec
        ) {
            $this->handleAssignment($node->var, $node, $comments);
        }

        if ($this->isOperationEntrypoint && $node instanceof Node\Expr\Throw_) {
            $responseType = $this->scope->handleThrowExtensions($node->expr);

            if ($responseType !== null) {
                $this->returnTypes[] = $responseType;
            }
        }
    }


    private function handleForeach(Node\Stmt\Foreach_ $node): void
    {
        if ($node->keyVar) {
            $this->handleAssignment($node->keyVar, new UnresolvedArrayKeyType(new UnresolvedParserNodeType($node->expr, $this->scope), $this->scope));
        }

        $this->handleAssignment($node->valueVar, new UnresolvedArrayItemType(new UnresolvedParserNodeType($node->expr, $this->scope), $this->scope));
    }


    private function handleReturnStatement(Node\Stmt\Return_ $node): void
    {
        if ($node->expr) {
            $this->returnTypes[] = new UnresolvedParserNodeType(
                node: $node->expr,
                scope: $this->scope,
                isFinalResponse: $this->isOperationEntrypoint,
            );

        } else {
            $this->returnTypes[] = new VoidType;
        }
    }


    /**
     * @param Comment[] $comments
     */
    private function handleAssignment(Node $varNode, Node\Expr|Type $valueNode, array $comments = []): void
    {
        $assignedType = $valueNode instanceof Type ? $valueNode : new UnresolvedParserNodeType($valueNode, $this->scope);

        if ($varNode instanceof Node\Expr\Variable) {
            $this->scope->assignVariable(
                varNode: $varNode,
                valueNode: $valueNode,
                comments: $comments,
            );

        } else if ($varNode instanceof Node\Expr\ArrayDimFetch || $varNode instanceof Node\Expr\PropertyFetch) {
            $assignedItemKey = $this->getRawArrayKeyValue($varNode instanceof Node\Expr\ArrayDimFetch ? $varNode->dim : $varNode->name);

            if ($assignedItemKey === null) {
                $assignedItemKey = 0;
            }

            if ($varNode->var instanceof Node\Expr\Variable) {
                $this->scope->mutateVariable(
                    varNode: $varNode->var,
                    attributes: [
                        $assignedItemKey => $assignedType,
                    ],
                );

            } else if ($varNode->var instanceof Node\Expr\ArrayDimFetch || $varNode->var instanceof Node\Expr\PropertyFetch) {
                $nestedKeys = $this->getNestedAccessKeys($varNode);

                $baseVariable = $nestedKeys['baseVariable'];
                $keyPath = $nestedKeys['keyPath'];

                if ($baseVariable instanceof Node\Expr\Variable) {
                    /** @var array<int|string, Type> $attributes */
                    $attributes = [];
                    $lastKeyIndex = array_key_last($keyPath);

                    /** @var array<int|string, Type> $currentLevel */
                    $currentLevel = &$attributes;

                    foreach ($keyPath as $keyIndex => $key) {
                        if ($keyIndex === $lastKeyIndex) {
                            if ($key === null) {
                                $currentLevel[] = $assignedType;

                            } else {
                                $currentLevel[$key] = $assignedType;
                            }

                        } else if ($key === null) {
                            $arrayType = new ArrayType;
                            $currentLevel[] = $arrayType;

                            /** @var array<int|string, Type> $currentLevel */
                            $currentLevel = &$arrayType->shape;

                        } else {
                            if (! isset($currentLevel[$key]) || ! $currentLevel[$key] instanceof ArrayType) {
                                $currentLevel[$key] = new ArrayType;
                            }

                            /** @var ArrayType $nestedArrayType */
                            $nestedArrayType = $currentLevel[$key];

                            /** @var array<int|string, Type> $currentLevel */
                            $currentLevel = &$nestedArrayType->shape;
                        }
                    }

                    $attributes = $this->normalizeNestedArrayTypes($attributes);

                    $this->scope->mutateVariable(
                        varNode: $baseVariable,
                        attributes: $attributes,
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
                    $hasIntegerKeys = array_any(array_keys($attributeType->shape), fn ($shapeKey) => is_int($shapeKey));

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
    private function getNestedAccessKeys(Node\Expr\ArrayDimFetch|Node\Expr\PropertyFetch $arrayAccessNode): array
    {
        $keyPath = [];
        $currentNode = $arrayAccessNode;

        while ($currentNode instanceof Node\Expr\ArrayDimFetch || $currentNode instanceof Node\Expr\PropertyFetch) {
            $keyPath[] = $this->getRawArrayKeyValue($currentNode instanceof Node\Expr\ArrayDimFetch ? $currentNode->dim : $currentNode->name);
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


    private function getNodeStartFilePos(Node $node): int
    {
        /** @var int */
        return $node->getAttribute('startFilePos');
    }

    private function getNodeEndFilePos(Node $node): int
    {
        /** @var int */
        return $node->getAttribute('endFilePos');
    }

    /**
     * @param Node[] $stmts
     */
    private function getBranchBodyStartFilePos(array $stmts, Node $fallbackNode): int
    {
        $firstStatement = $stmts[0] ?? null;

        if (! $firstStatement instanceof Node) {
            return $this->getNodeEndFilePos($fallbackNode);
        }

        $startFilePos = $this->getNodeStartFilePos($firstStatement);

        foreach ($firstStatement->getComments() as $comment) {
            $startFilePos = min($startFilePos, $comment->getStartFilePos());
        }

        return $startFilePos;
    }

    private function handleBranchEnter(Node $node): void
    {
        $this->handleTernaryBranchEnter($node);

        if ($node instanceof Node\Stmt\If_) {
            $condition = new PhpCondition($node, $this->scope);
            $this->conditionStack[] = $condition;
            $this->branchIndexStack[] = 0;
            $this->scope->eventLog->enterBranch(
                $condition->id,
                0,
                $this->getBranchBodyStartFilePos($node->stmts, $node),
                $condition,
            );

            TypeNarrower::emitNarrowingEvents($node->cond, $this->scope, $condition);

        } else if ($node instanceof Node\Stmt\ElseIf_) {
            $condition = end($this->conditionStack);

            if ($condition !== false) {
                $branchIndex = array_pop($this->branchIndexStack) + 1;
                $this->branchIndexStack[] = $branchIndex;
                $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
                $this->scope->eventLog->enterBranch(
                    $condition->id,
                    $branchIndex,
                    $this->getBranchBodyStartFilePos($node->stmts, $node),
                );

                if ($condition->node instanceof Node\Stmt\If_) {
                    TypeNarrower::emitPreviousElseIfNarrowingEvents($condition->node, $node, $this->scope, $condition);
                }

                TypeNarrower::emitNarrowingEvents($node->cond, $this->scope, $condition);
            }

        } else if ($node instanceof Node\Stmt\Else_) {
            $condition = end($this->conditionStack);

            if ($condition !== false) {
                $branchIndex = array_pop($this->branchIndexStack) + 1;
                $this->branchIndexStack[] = $branchIndex;
                $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
                $this->scope->eventLog->enterBranch(
                    $condition->id,
                    $branchIndex,
                    $this->getBranchBodyStartFilePos($node->stmts, $node),
                );

                if ($condition->node instanceof Node\Stmt\If_) {
                    TypeNarrower::emitElseNarrowingEvents($condition->node, $this->scope, $condition);
                }
            }

        } else if ($node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            $condition = new PhpCondition($node, $this->scope);
            $this->conditionStack[] = $condition;
            $this->branchIndexStack[] = 0;

            // For loops: enter branch after the header (condition/expression) so that
            // header expressions resolve at the parent branch level.
            $branchStartPos = match (true) {
                $node instanceof Node\Stmt\Foreach_ => $this->getNodeEndFilePos($node->valueVar),
                $node instanceof Node\Stmt\For_ => $this->getNodeEndFilePos($node->cond[0] ?? $node),
                $node instanceof Node\Stmt\While_ => $this->getNodeEndFilePos($node->cond),
                $node instanceof Node\Stmt\TryCatch => $this->getBranchBodyStartFilePos($node->stmts, $node), // @phpstan-ignore instanceof.alwaysTrue
                default => $this->getNodeStartFilePos($node),
            };

            $this->scope->eventLog->enterBranch($condition->id, 0, $branchStartPos, $condition);

        } else if ($node instanceof Node\Stmt\Switch_) {
            $condition = new PhpCondition($node, $this->scope);
            $this->conditionStack[] = $condition;
            $this->branchIndexStack[] = -1;

        } else if ($node instanceof Node\Stmt\Catch_) {
            $condition = end($this->conditionStack);

            if ($condition !== false) {
                $branchIndex = array_pop($this->branchIndexStack) + 1;
                $this->branchIndexStack[] = $branchIndex;
                $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
                $this->scope->eventLog->enterBranch(
                    $condition->id,
                    $branchIndex,
                    $this->getBranchBodyStartFilePos($node->stmts, $node),
                );
            }

        } else if ($node instanceof Node\Stmt\Finally_) {
            $condition = end($this->conditionStack);

            if ($condition !== false) {
                $branchIndex = array_pop($this->branchIndexStack) + 1;
                $this->branchIndexStack[] = $branchIndex;
                $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
                $this->scope->eventLog->enterBranch(
                    $condition->id,
                    $branchIndex,
                    $this->getBranchBodyStartFilePos($node->stmts, $node),
                );
            }

        } else if ($node instanceof Node\Stmt\Case_) {
            $condition = end($this->conditionStack);

            if ($condition !== false) {
                $branchIndex = array_pop($this->branchIndexStack);

                if ($branchIndex >= 0) {
                    $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
                }

                $branchIndex++;
                $this->branchIndexStack[] = $branchIndex;
                $this->scope->eventLog->enterBranch(
                    $condition->id,
                    $branchIndex,
                    $this->getBranchBodyStartFilePos($node->stmts, $node),
                );

                if ($condition->node instanceof Node\Stmt\Switch_) {
                    TypeNarrower::emitPreviousCaseNarrowingEvents($condition->node, $node, $this->scope, $condition);
                    TypeNarrower::emitCaseNarrowingEvents($condition->node, $node, $this->scope, $condition);
                }
            }

        } else if ($node instanceof Node\Expr\Match_) {
            $condition = new PhpCondition($node, $this->scope);
            $this->conditionStack[] = $condition;
            $this->branchIndexStack[] = -1;

        } else if ($node instanceof Node\MatchArm) {
            $condition = end($this->conditionStack);

            if ($condition !== false) {
                $branchIndex = array_pop($this->branchIndexStack);

                if ($branchIndex >= 0) {
                    $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
                }

                $branchIndex++;
                $this->branchIndexStack[] = $branchIndex;
                $this->scope->eventLog->enterBranch(
                    $condition->id,
                    $branchIndex,
                    $this->getNodeStartFilePos($node->body),
                );

                if ($condition->node instanceof Node\Expr\Match_) {
                    TypeNarrower::emitPreviousMatchArmNarrowingEvents($condition->node, $node, $this->scope, $condition);
                    TypeNarrower::emitMatchArmNarrowingEvents($condition->node->cond, $node, $this->scope, $condition);
                }
            }

        } else if ($node instanceof Node\Expr\Ternary && $node->if !== null) {
            // Only full ternaries (`a ? b : c`) introduce a true/false branch pair.
            // Short ternaries (`a ?: c`) reuse the condition as the true value and
            // are left untracked.
            $condition = new PhpCondition($node, $this->scope);
            $this->conditionStack[] = $condition;
            $this->branchIndexStack[] = 0;
        }
    }


    /**
     * Enter the true/false branch of the active ternary when traversal reaches its
     * `if`/`else` expression, emitting the condition's narrowings (negated for the
     * `else` side). The branch is exited again in {@see handleBranchExit()}.
     */
    private function handleTernaryBranchEnter(Node $node): void
    {
        $condition = end($this->conditionStack);

        if (! $condition instanceof PhpCondition || ! $condition->node instanceof Node\Expr\Ternary) {
            return;
        }

        $ternary = $condition->node;

        if ($node === $ternary->if) {
            $this->scope->eventLog->enterBranch($condition->id, 0, $this->getNodeStartFilePos($node));

            TypeNarrower::emitNarrowingEvents($ternary->cond, $this->scope, $condition);

        } else if ($node === $ternary->else) {
            $this->scope->eventLog->exitBranch($this->getNodeStartFilePos($node));
            $this->scope->eventLog->enterBranch($condition->id, 1, $this->getNodeStartFilePos($node));

            TypeNarrower::emitNegatedNarrowingEvents($ternary->cond, $this->scope, $condition);
        }
    }


    private function handleBranchExit(Node $node): void
    {
        if ($node instanceof Node\Expr\Ternary && $node->if !== null) {
            $condition = end($this->conditionStack);

            if ($condition instanceof PhpCondition && $condition->node === $node) {
                array_pop($this->conditionStack);
                array_pop($this->branchIndexStack);
                $this->scope->eventLog->exitBranch($this->getNodeEndFilePos($node));
            }
        }

        if ($node instanceof Node\Stmt\Switch_ || $node instanceof Node\Expr\Match_) {
            array_pop($this->conditionStack);
            $activeBranchIndex = array_pop($this->branchIndexStack);

            if (is_int($activeBranchIndex) && $activeBranchIndex >= 0) {
                $this->scope->eventLog->exitBranch($this->getNodeEndFilePos($node));
            }
        }

        if ($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\TryCatch
        ) {
            $condition = end($this->conditionStack) ?: null;

            array_pop($this->conditionStack);
            array_pop($this->branchIndexStack);
            $this->scope->eventLog->exitBranch($this->getNodeEndFilePos($node));

            // Early-return guard: an `if`/`elseif` chain with no `else` whose every
            // branch breaks out (return/exit/throw). The code after it is only
            // reached via fall-through, i.e. when every condition was false, so the
            // negated conditions narrow variables from there on.
            if ($node instanceof Node\Stmt\If_
                && $condition instanceof PhpCondition
                && $node->else === null
                && $condition->allBranchesBreakOut()
            ) {
                TypeNarrower::emitElseNarrowingEvents(
                    $node,
                    $this->scope,
                    $condition,
                    $this->getNodeEndFilePos($node),
                );
            }
        }
    }
}
