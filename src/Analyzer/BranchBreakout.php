<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\NeverType;
use PhpParser\Node;
use Throwable;

class BranchBreakout
{
    public function __construct(
        private readonly Scope $scope,
    ) {}

    public function statementBreaksOut(Node $node): bool
    {
        return $this->getBreakOutNode($node) !== null;
    }

    public function getBreakOutNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Return_) {
            return $node;
        }

        if ($node instanceof Node\Stmt\Expression
            && ($node->expr instanceof Node\Expr\Exit_
                || $node->expr instanceof Node\Expr\Throw_
                || $this->expressionNeverReturns($node->expr))
        ) {
            return $node->expr;
        }

        if ($node instanceof Node\Stmt\If_) {
            return $this->getIfBreakOutNode($node);
        }

        return null;
    }

    /**
     * @param Node[] $statements
     */
    public function getBreakOutNodeFromStatements(array $statements): ?Node
    {
        foreach ($statements as $statement) {
            $breakOutNode = $this->getBreakOutNode($statement);

            if ($breakOutNode !== null) {
                return $breakOutNode;
            }
        }

        return null;
    }

    private function getIfBreakOutNode(Node\Stmt\If_ $node): ?Node
    {
        $breakOutNode = $this->getBreakOutNodeFromStatements($node->stmts);

        if ($breakOutNode === null) {
            return null;
        }

        foreach ($node->elseifs as $elseif) {
            if ($this->getBreakOutNodeFromStatements($elseif->stmts) === null) {
                return null;
            }
        }

        if ($node->else === null || $this->getBreakOutNodeFromStatements($node->else->stmts) === null) {
            return null;
        }

        return $breakOutNode;
    }

    private function expressionNeverReturns(Node\Expr $expr): bool
    {
        if (! $expr instanceof Node\Expr\FuncCall
            && ! $expr instanceof Node\Expr\MethodCall
            && ! $expr instanceof Node\Expr\NullsafeMethodCall
            && ! $expr instanceof Node\Expr\StaticCall
        ) {
            return false;
        }

        try {
            // Peek at the call's return type only. This runs before the
            // surrounding statements are analyzed, so extension side effects
            // (request-body capture, variable mutation) must not fire from a
            // call whose arguments reference not-yet-assigned vars.
            $returnType = $this->scope->withoutSideEffects(
                fn () => $this->scope->resolveType($expr)->unwrapType($this->scope->config),
            );

            return $returnType instanceof NeverType;

        } catch (Throwable) {
            return false;
        }
    }
}
