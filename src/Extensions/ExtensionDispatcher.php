<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Ast\PartialApplicationResolver;
use AutoDoc\Analyzer\Narrowing\NarrowingFact;
use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use AutoDoc\OpenApi\Operation;
use AutoDoc\Route;
use PhpParser\Node;
use WeakMap;

/**
 * Dispatches the configured extensions for the current scope. The grouped
 * extension list lives on `Config` (resolved once); this only runs them.
 */
final class ExtensionDispatcher
{
    /** @var WeakMap<Node, true> */
    private WeakMap $nodesWithHandledSideEffects;

    private bool $suppressSideEffects = false;

    public function __construct(
        private readonly Scope $scope,
    ) {
        $this->nodesWithHandledSideEffects = new WeakMap;
    }

    public function getReturnTypeFromMethodCallExtensions(MethodCallContext $context): ?Type
    {
        foreach ($this->getExtensionsOfType(MethodCallExtension::class) as $extensionClass) {
            $returnType = (new $extensionClass)->getReturnType($context);

            if ($returnType instanceof Type) {
                return $returnType;
            }
        }

        return null;
    }

    public function getReturnTypeFromFuncCallExtensions(FuncCallContext $context): ?Type
    {
        foreach ($this->getExtensionsOfType(FuncCallExtension::class) as $extensionClass) {
            $returnType = (new $extensionClass)->getReturnType($context);

            if ($returnType instanceof Type) {
                return $returnType;
            }
        }

        return null;
    }

    public function getReturnTypeFromStaticCallExtensions(StaticCallContext $context): ?Type
    {
        foreach ($this->getExtensionsOfType(StaticCallExtension::class) as $extensionClass) {
            $returnType = (new $extensionClass)->getReturnType($context);

            if ($returnType instanceof Type) {
                return $returnType;
            }
        }

        return null;
    }

    /**
     * Run side-effect hooks once per call and analysis pass, in configured order
     * with built-ins last. Suppressed during return-type peeks.
     */
    public function runSideEffectExtensions(MethodCallContext|FuncCallContext|StaticCallContext $context): void
    {
        if (PartialApplicationResolver::appliesTo($context->node)) {
            return;
        }

        if ($this->suppressSideEffects || isset($this->nodesWithHandledSideEffects[$context->node])) {
            return;
        }

        $this->nodesWithHandledSideEffects[$context->node] = true;

        if ($context instanceof MethodCallContext) {
            foreach ($this->getExtensionsOfType(MethodCallExtension::class) as $extensionClass) {
                (new $extensionClass)->handleSideEffect($context);
            }

        } else if ($context instanceof FuncCallContext) {
            foreach ($this->getExtensionsOfType(FuncCallExtension::class) as $extensionClass) {
                (new $extensionClass)->handleSideEffect($context);
            }

        } else {
            foreach ($this->getExtensionsOfType(StaticCallExtension::class) as $extensionClass) {
                (new $extensionClass)->handleSideEffect($context);
            }
        }
    }

    /**
     * @return list<NarrowingFact>
     */
    public function getNarrowingsFromFuncCallExtensions(FuncCallContext $context, bool $negated): array
    {
        foreach ($this->getExtensionsOfType(FuncCallExtension::class) as $extensionClass) {
            (new $extensionClass)->narrowTypeFromCondition($context, $negated);
        }

        return $context->getNarrowingFacts();
    }

    /**
     * @return list<NarrowingFact>
     */
    public function getNarrowingsFromMethodCallExtensions(MethodCallContext $context, bool $negated): array
    {
        foreach ($this->getExtensionsOfType(MethodCallExtension::class) as $extensionClass) {
            (new $extensionClass)->narrowTypeFromCondition($context, $negated);
        }

        return $context->getNarrowingFacts();
    }

    /**
     * @return list<NarrowingFact>
     */
    public function getNarrowingsFromStaticCallExtensions(StaticCallContext $context, bool $negated): array
    {
        foreach ($this->getExtensionsOfType(StaticCallExtension::class) as $extensionClass) {
            (new $extensionClass)->narrowTypeFromCondition($context, $negated);
        }

        return $context->getNarrowingFacts();
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getReturnTypeFromClassExtensions(PhpClass $phpClass): ?Type
    {
        $this->handleExpectedRequestTypeFromClassExtensions($phpClass);

        foreach ($this->getExtensionsOfType(ClassExtension::class) as $extensionClass) {
            $returnType = (new $extensionClass)->getReturnType($phpClass);

            if ($returnType !== null) {
                return $returnType;
            }
        }

        return null;
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function handleExpectedRequestTypeFromClassExtensions(PhpClass $phpClass): void
    {
        $route = $this->scope->route;

        if ($this->suppressSideEffects || $route === null || $route->hasRequestBodyTypeFromClass($phpClass->className)) {
            return;
        }

        foreach ($this->getExtensionsOfType(ClassExtension::class) as $extensionClass) {
            $requestType = (new $extensionClass)->getRequestType($phpClass);

            if ($requestType !== null) {
                $route->addRequestBodyTypeFromClass($phpClass->className, $requestType);

                return;
            }
        }
    }

    public function handleOperationExtensions(Operation $operation, Route $route): Operation
    {
        foreach ($this->getExtensionsOfType(OperationExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $extensionResult = $extension->handle($operation, $route, $this->scope);

            if ($extensionResult !== null) {
                $operation = $extensionResult;
            }
        }

        return $operation;
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getPropertyTypeFromExtensions(PhpClass $phpClass, string $propertyName): ?Type
    {
        foreach ($this->getExtensionsOfType(ClassExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $propertyType = $extension->getPropertyType($phpClass, $propertyName);

            if ($propertyType !== null) {
                return $propertyType;
            }
        }

        return null;
    }

    public function handleThrowExtensions(Node\Expr $expr): ?Type
    {
        $throw = new ThrowContext($expr, $this->scope);

        foreach ($this->getExtensionsOfType(ThrowExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $returnedType = $extension->getReturnType($throw);

            if ($returnedType !== null) {
                return $returnedType;
            }
        }

        return null;
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function handleTypeScriptExportExtensions(PhpClass $phpClass, Type $type): Type
    {
        foreach ($this->getExtensionsOfType(TypeScriptExportExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $returnedType = $extension->handle($phpClass, $type);

            if ($returnedType !== null) {
                $type = $returnedType;
            }
        }

        return $type;
    }

    /**
     * @template T of object
     * @param class-string<T> $extensionTypeClass
     * @return array<class-string<T>>
     */
    private function getExtensionsOfType(string $extensionTypeClass): array
    {
        /** @var array<class-string<T>> */
        $extensions = $this->scope->config->getExtensions()[$extensionTypeClass] ?? [];

        return $extensions;
    }

    /**
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withoutSideEffects(callable $callback): mixed
    {
        $initialValue = $this->suppressSideEffects;
        $this->suppressSideEffects = true;

        try {
            return $callback();

        } finally {
            $this->suppressSideEffects = $initialValue;
        }
    }

    public function recordRequestBodyType(Type $type): void
    {
        if ($this->suppressSideEffects) {
            return;
        }

        $this->scope->route?->addRequestBodyType($type);
    }
}
