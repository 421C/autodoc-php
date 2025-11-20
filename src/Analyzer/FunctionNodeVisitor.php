<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Analyzer\Traits\AnalyzesFunctionNodes;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use Override;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class FunctionNodeVisitor extends NodeVisitorAbstract
{
    use AnalyzesFunctionNodes;

    public function __construct(
        private Scope $scope,
        private Scope $parentScope,
        private bool $analyzeReturnValue,
        private bool $isOperationEntrypoint = false,

        /**
         * @var PhpFunctionArgument[]
         */
        private array $args = [],
    ) {}

    /** @var Type[] */
    public array $returnTypes = [];

    private int $currentDepth = 0;

    /**
     * @return Node[]
     */
    #[Override]
    public function beforeTraverse(array $nodes): array
    {
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

                $this->scope->transferVariablesFrom($this->parentScope, $usedVarNames);
            }

            return $node->stmts;
        }

        if ($node instanceof Node\Expr\ArrowFunction) {
            $this->handleParameters($node->params, $node->getDocComment());
            $this->scope->transferVariablesFrom($this->parentScope);

            $this->scope->className = $this->parentScope->className;

            $this->returnTypes = [
                new UnresolvedParserNodeType(
                    node: $node->expr,
                    scope: $this->scope,
                ),
            ];
        }

        return [];
    }


    /**
     * @return null|NodeVisitor::*
     */
    #[Override]
    public function enterNode(Node $node)
    {
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
        if ($node instanceof Node\Stmt\If_ ||
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
}
