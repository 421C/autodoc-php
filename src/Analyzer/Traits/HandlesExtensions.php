<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Traits;

use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\Analyzer\MethodCallContext;
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
        return $this->runExtensions(
            extensionTypeClass: MethodCallExtension::class,
            node: $context->node,
            getRequestType: fn ($ext) => $ext->getRequestType($context),
            getReturnType: fn ($ext) => $ext->getReturnType($context),
        );
    }

    public function getReturnTypeFromFuncCallExtensions(FuncCallContext $context): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: FuncCallExtension::class,
            node: $context->node,
            getRequestType: fn ($ext) => $ext->getRequestType($context),
            getReturnType: fn ($ext) => $ext->getReturnType($context),
        );
    }

    public function getReturnTypeFromStaticCallExtensions(StaticCallContext $context): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: StaticCallExtension::class,
            node: $context->node,
            getRequestType: fn ($ext) => $ext->getRequestType($context),
            getReturnType: fn ($ext) => $ext->getReturnType($context),
        );
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getReturnTypeFromClassExtensions(PhpClass $phpClass): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: ClassExtension::class,
            node: $phpClass,
            getRequestType: fn ($ext) => $ext->getRequestType($phpClass),
            getReturnType: fn ($ext) => $ext->getReturnType($phpClass),
        );
    }

    public function handleExpectedRequestTypeFromExtensions(MethodCallContext|FuncCallContext|StaticCallContext $context): void
    {
        if ($context instanceof MethodCallContext) {
            $this->runExtensions(
                extensionTypeClass: MethodCallExtension::class,
                node: $context->node,
                getRequestType: fn ($ext) => $ext->getRequestType($context),
                getReturnType: fn ($ext) => $ext->getReturnType($context),
                returnType: false,
            );

        } else if ($context instanceof FuncCallContext) {
            $this->runExtensions(
                extensionTypeClass: FuncCallExtension::class,
                node: $context->node,
                getRequestType: fn ($ext) => $ext->getRequestType($context),
                getReturnType: fn ($ext) => $ext->getReturnType($context),
                returnType: false,
            );

        } else {
            $this->runExtensions(
                extensionTypeClass: StaticCallExtension::class,
                node: $context->node,
                getRequestType: fn ($ext) => $ext->getRequestType($context),
                getReturnType: fn ($ext) => $ext->getReturnType($context),
                returnType: false,
            );
        }
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function handleExpectedRequestTypeFromClassExtensions(PhpClass $phpClass): void
    {
        $this->runExtensions(
            extensionTypeClass: ClassExtension::class,
            node: $phpClass,
            getRequestType: fn ($ext) => $ext->getRequestType($phpClass),
            getReturnType: fn ($ext) => $ext->getReturnType($phpClass),
            returnType: false,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $extensionTypeClass
     * @param \Closure(T): ?Type $getRequestType
     * @param \Closure(T): ?Type $getReturnType
     *
     * @return ($returnType is true ? Type|null : null)
     */
    private function runExtensions(string $extensionTypeClass, object $node, \Closure $getRequestType, \Closure $getReturnType, bool $returnType = true): ?Type
    {
        $requestTypeHandled = isset($this->objectsHandlingRequestBody[$node]);
        $returnTypeHandled = ! $returnType;
        $result = null;

        foreach ($this->getExtensionsOfType($extensionTypeClass) as $extensionClass) {
            $extension = new $extensionClass;

            if (! $requestTypeHandled) {
                $requestResult = $getRequestType($extension);

                if ($requestResult instanceof Type) {
                    $requestTypeHandled = true;

                    $this->objectsHandlingRequestBody[$node] = true;
                    $this->route?->addRequestBodyType($requestResult);
                }
            }

            if (! $returnTypeHandled) {
                $result = $getReturnType($extension);

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
