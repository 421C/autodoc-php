<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Analyzer\Traits\AnalyzesFunctionNodes;
use AutoDoc\DataTypes\Type;
use Override;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class ClassMethodNodeVisitor extends NodeVisitorAbstract
{
    use AnalyzesFunctionNodes;

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
            $this->inTargetMethod = strcasecmp($node->name->toString(), $this->methodName) === 0;

            if (! $this->inTargetMethod) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }

            $this->targetMethodExists = true;

            $this->handleParameters($node->params, $node->getDocComment());
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

        if ($this->handleConditionNode($node)) {
            return null;
        }

        if ($node instanceof Node\Stmt\Expression) {
            $this->handleExpression($node);
        }

        if ($this->analyzeReturnValue && $node instanceof Node\Stmt\Return_) {
            $this->handleReturnStatement($node);
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

        if ($this->handleConditionEnd($node)) {
            return null;
        }

        return null;
    }
}
