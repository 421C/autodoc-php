<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Traits;

use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\Analyzer\MethodCallContext;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\NarrowingTarget;
use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Analyzer\StaticCallContext;
use AutoDoc\Analyzer\ThrowContext;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\ClassExtension;
use AutoDoc\Extensions\FuncCallExtension;
use AutoDoc\Extensions\MethodCallExtension;
use AutoDoc\Extensions\OperationExtension;
use AutoDoc\Extensions\StaticCallExtension;
use AutoDoc\Extensions\ThrowExtension;
use AutoDoc\Extensions\TypeScriptExportExtension;
use AutoDoc\OpenApi\Operation;
use AutoDoc\Route;
use PhpParser\Node;

/**
 * Dispatches the configured extensions for the current scope. The grouped
 * extension list lives on `Config` (resolved once); this only runs them.
 *
 * @phpstan-require-extends Scope
 */
trait HandlesExtensions
{
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
     * @return list<array{NarrowingTarget, Narrowing}>
     */
    public function getNarrowingsFromFuncCallExtensions(FuncCallContext $context, bool $negated): array
    {
        foreach ($this->getExtensionsOfType(FuncCallExtension::class) as $extensionClass) {
            (new $extensionClass)->narrowTypeFromCondition($context, $negated);
        }

        return $context->getTypeNarrowings();
    }

    /**
     * @return list<array{NarrowingTarget, Narrowing}>
     */
    public function getNarrowingsFromMethodCallExtensions(MethodCallContext $context, bool $negated): array
    {
        foreach ($this->getExtensionsOfType(MethodCallExtension::class) as $extensionClass) {
            (new $extensionClass)->narrowTypeFromCondition($context, $negated);
        }

        return $context->getTypeNarrowings();
    }

    /**
     * @return list<array{NarrowingTarget, Narrowing}>
     */
    public function getNarrowingsFromStaticCallExtensions(StaticCallContext $context, bool $negated): array
    {
        foreach ($this->getExtensionsOfType(StaticCallExtension::class) as $extensionClass) {
            (new $extensionClass)->narrowTypeFromCondition($context, $negated);
        }

        return $context->getTypeNarrowings();
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getReturnTypeFromClassExtensions(PhpClass $phpClass): ?Type
    {
        return $this->runClassExtensions($phpClass);
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function handleExpectedRequestTypeFromClassExtensions(PhpClass $phpClass): void
    {
        $this->runClassExtensions($phpClass, returnType: false);
    }

    /**
     * A class extension may act as the request body (form-request pattern) and/or
     * resolve a return type. Request capture is recorded onto the route once per
     * class and suppressed during return-type peeks.
     *
     * @param PhpClass<object> $phpClass
     * @return ($returnType is true ? Type|null : null)
     */
    private function runClassExtensions(PhpClass $phpClass, bool $returnType = true): ?Type
    {
        $requestTypeHandled = $this->suppressSideEffects || isset($this->classesHandlingRequestBody[$phpClass]);
        $returnTypeHandled = ! $returnType;
        $result = null;

        foreach ($this->getExtensionsOfType(ClassExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            if (! $requestTypeHandled) {
                $requestResult = $extension->getRequestType($phpClass);

                if ($requestResult instanceof Type) {
                    $requestTypeHandled = true;

                    $this->classesHandlingRequestBody[$phpClass] = true;
                    $this->route?->addRequestBodyType($requestResult);
                }
            }

            if (! $returnTypeHandled) {
                $result = $extension->getReturnType($phpClass);

                if ($result instanceof Type) {
                    $returnTypeHandled = true;
                }
            }

            if ($requestTypeHandled && $returnTypeHandled) {
                break;
            }
        }

        return $result;
    }

    public function handleOperationExtensions(Operation $operation, Route $route): Operation
    {
        foreach ($this->getExtensionsOfType(OperationExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $extensionResult = $extension->handle($operation, $route, $this);

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
        $throw = new ThrowContext($expr, $this);

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
        $extensions = $this->config->getExtensions()[$extensionTypeClass] ?? [];

        return $extensions;
    }
}
