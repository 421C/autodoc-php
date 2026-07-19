<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\NeverType;
use PhpParser\Node;
use Throwable;
use WeakMap;

class BranchBreakout
{
    /**
     * Maps a Node\Stmt\* object to the node that makes it break out
     * (return/throw/exit/never-returning call), or null when it doesn't.
     *
     * Cached because the same statements are checked repeatedly: once by
     * the scan on entering the function body, and again by every enclosing
     * condition asking whether the branch containing them exits.
     *
     * @var WeakMap<Node, ?Node>
     */
    private WeakMap $breakOutNodes;

    /**
     * Function name → whether calls to it never return. The verdict costs a
     * full body analysis of the callee, and always-throwing helpers tend to
     * be called from many places, so it is cached for the whole process.
     * Keyed by Config because extensions and resolution settings can change
     * a function's return type.
     *
     * @var ?WeakMap<Config, array<string, bool>>
     */
    private static ?WeakMap $neverReturningFunctions = null;

    public function __construct(
        private readonly Scope $scope,
    ) {
        $this->breakOutNodes = new WeakMap;
    }

    public function statementBreaksOut(Node $node): bool
    {
        return $this->getBreakOutNode($node) !== null;
    }

    public function getBreakOutNode(Node $node): ?Node
    {
        if ($this->breakOutNodes->offsetExists($node)) {
            return $this->breakOutNodes[$node];
        }

        $breakOutNode = $this->resolveBreakOutNode($node);

        $this->breakOutNodes[$node] = $breakOutNode;

        return $breakOutNode;
    }

    private function resolveBreakOutNode(Node $node): ?Node
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

        $functionName = $expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name
            ? $expr->name->name
            : null;

        self::$neverReturningFunctions ??= new WeakMap;

        if ($functionName !== null) {
            $cachedVerdicts = self::$neverReturningFunctions[$this->scope->config] ?? [];

            if (array_key_exists($functionName, $cachedVerdicts)) {
                return $cachedVerdicts[$functionName];
            }
        }

        try {
            // Peek at the call's return type only. This runs before the
            // surrounding statements are analyzed, so extension side effects
            // (request-body capture, variable mutation) must not fire from a
            // call whose arguments reference not-yet-assigned vars.
            $returnType = $this->scope->withoutSideEffects(
                fn () => $this->scope->resolveType($expr)->unwrapType($this->scope->config),
            );

            $neverReturns = $returnType instanceof NeverType;

        } catch (Throwable) {
            $neverReturns = false;
        }

        if ($functionName !== null) {
            // Re-read: the peek may have cached other functions recursively.
            $cachedVerdicts = self::$neverReturningFunctions[$this->scope->config] ?? [];
            $cachedVerdicts[$functionName] = $neverReturns;
            self::$neverReturningFunctions[$this->scope->config] = $cachedVerdicts;
        }

        return $neverReturns;
    }
}
