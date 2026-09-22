<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Ast;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\FuncCallContext;
use AutoDoc\Extensions\MethodCallContext;
use AutoDoc\Extensions\StaticCallContext;
use PhpParser\Node;
use ReflectionException;
use ReflectionFunction;

/**
 * Resolves a call whose argument list holds `?` or `...` placeholders.
 */
class PartialApplicationResolver
{
    public function __construct(
        private readonly Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $node,
        private readonly Scope $scope,
    ) {}

    /**
     * Not using `CallLike::isPartialFunctionApplication()` (php-parser 5.9) because PHPStan extensions that
     * call into autodoc run inside phpstan.phar, whose bundled php-parser may predate the method.
     */
    public static function appliesTo(Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $node): bool
    {
        foreach ($node->getRawArgs() as $arg) {
            if ($arg instanceof Node\VariadicPlaceholder || $arg instanceof Node\ArgPlaceholder) {
                return true;
            }
        }

        return false;
    }


    public function resolveType(): Type
    {
        if (! $this->node->isFirstClassCallable()) {
            return new CallableType;
        }

        return $this->resolveTargetType() ?? new CallableType;
    }


    private function resolveTargetType(): ?Type
    {
        $node = $this->node;

        if ($node instanceof Node\Expr\FuncCall) {
            return $this->resolveFunctionType($node);
        }

        if ($node instanceof Node\Expr\StaticCall) {
            return $this->resolveStaticMethodType($node);
        }

        return $this->resolveMethodType($node);
    }


    private function resolveFunctionType(Node\Expr\FuncCall $node): ?Type
    {
        $functionName = new FuncCallContext(node: $node, scope: $this->scope)->functionName;

        if ($functionName === null) {
            $targetType = $this->scope->resolveType($node->name);

            return $targetType instanceof CallableType ? $targetType : null;
        }

        try {
            $reflection = new ReflectionFunction($functionName);

        } catch (ReflectionException) {
            return null;
        }

        return new CallableType(
            phpCallable: new PhpCallable(
                scope: $this->scope,
                reflection: $reflection,
            ),
        );
    }


    private function resolveMethodType(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): ?Type
    {
        $context = new MethodCallContext(node: $node, scope: $this->scope);
        $varType = $context->getVarType();

        if (! ($varType instanceof ObjectType || $varType instanceof ArrayType) || ! isset($varType->className)) {
            return null;
        }

        return new CallableType(
            phpCallable: $this->scope->getPhpClassInDeeperScope($varType->className)->getMethod(
                name: $context->methodName,
            ),
        );
    }


    private function resolveStaticMethodType(Node\Expr\StaticCall $node): ?Type
    {
        $context = new StaticCallContext(node: $node, scope: $this->scope);

        if ($context->className === null || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        return new CallableType(
            phpCallable: $this->scope->getPhpClassInDeeperScope($context->className)->getMethod(
                name: $context->methodName,
            ),
        );
    }
}
