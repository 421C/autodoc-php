<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\Analyzer\MethodCallContext;
use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Analyzer\StaticCallContext;
use AutoDoc\Analyzer\ThrowContext;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\BuiltIn\ArrayFuncCall;
use AutoDoc\Extensions\ClassExtension;
use AutoDoc\Extensions\FuncCallExtension;
use AutoDoc\Extensions\MethodCallExtension;
use AutoDoc\Extensions\OperationExtension;
use AutoDoc\Extensions\StaticCallExtension;
use AutoDoc\Extensions\ThrowExtension;
use AutoDoc\Extensions\TypeScriptExportExtension;
use AutoDoc\OpenApi\Operation;

class ExtensionHandler
{
    public function __construct(
        private readonly Scope $scope,
    ) {}

    /**
     * @var array<class-string, array<class-string>>
     */
    private static array $extensions;

    /**
     * @template T of object
     * @param class-string<T> $extensionTypeClass
     * @return array<class-string<T>>
     */
    private function getExtensions(string $extensionTypeClass): array
    {
        if (! isset(self::$extensions)) {
            self::$extensions = [
                FuncCallExtension::class => [
                    ArrayFuncCall::class,
                ],
            ];

            foreach ($this->scope->config->data['extensions'] ?? [] as $extensionClass) {
                if (is_subclass_of($extensionClass, MethodCallExtension::class)) {
                    self::$extensions[MethodCallExtension::class][] = $extensionClass;

                } else if (is_subclass_of($extensionClass, FuncCallExtension::class)) {
                    self::$extensions[FuncCallExtension::class][] = $extensionClass;

                } else if (is_subclass_of($extensionClass, StaticCallExtension::class)) {
                    self::$extensions[StaticCallExtension::class][] = $extensionClass;

                } else if (is_subclass_of($extensionClass, ClassExtension::class)) {
                    self::$extensions[ClassExtension::class][] = $extensionClass;

                } else if (is_subclass_of($extensionClass, OperationExtension::class)) {
                    self::$extensions[OperationExtension::class][] = $extensionClass;

                } else if (is_subclass_of($extensionClass, ThrowExtension::class)) {
                    self::$extensions[ThrowExtension::class][] = $extensionClass;

                } else if (is_subclass_of($extensionClass, TypeScriptExportExtension::class)) {
                    self::$extensions[TypeScriptExportExtension::class][] = $extensionClass;
                }
            }
        }

        /** @var array<class-string<T>> */
        $extensions = self::$extensions[$extensionTypeClass] ?? [];

        return $extensions;
    }

    /**
     * @return ($getReturnType is true ? Type|null : null)
     */
    public function handleMethodCallExtensions(MethodCallContext $context, bool $getReturnType = true): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: MethodCallExtension::class,
            node: $context->node,
            getRequestType: fn ($ext) => $ext->getRequestType($context),
            getReturnType: fn ($ext) => $ext->getReturnType($context),
            returnType: $getReturnType,
        );
    }

    /**
     * @return ($getReturnType is true ? Type|null : null)
     */
    public function handleFuncCallExtensions(FuncCallContext $context, bool $getReturnType = true): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: FuncCallExtension::class,
            node: $context->node,
            getRequestType: fn ($ext) => $ext->getRequestType($context),
            getReturnType: fn ($ext) => $ext->getReturnType($context),
            returnType: $getReturnType,
        );
    }

    /**
     * @return ($getReturnType is true ? Type|null : null)
     */
    public function handleStaticCallExtensions(StaticCallContext $context, bool $getReturnType = true): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: StaticCallExtension::class,
            node: $context->node,
            getRequestType: fn ($ext) => $ext->getRequestType($context),
            getReturnType: fn ($ext) => $ext->getReturnType($context),
            returnType: $getReturnType,
        );
    }

    /**
     * @param PhpClass<object> $phpClass
     *
     * @return ($getReturnType is true ? Type|null : null)
     */
    public function handleClassExtensions(PhpClass $phpClass, bool $getReturnType = true): ?Type
    {
        return $this->runExtensions(
            extensionTypeClass: ClassExtension::class,
            node: $phpClass,
            getRequestType: fn ($ext) => $ext->getRequestType($phpClass),
            getReturnType: fn ($ext) => $ext->getReturnType($phpClass),
            returnType: $getReturnType,
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
        $requestTypeHandled = isset($this->scope->objectsHandlingRequestBody[$node]);
        $returnTypeHandled = ! $returnType;
        $result = null;

        foreach ($this->getExtensions($extensionTypeClass) as $extensionClass) {
            $extension = new $extensionClass;

            if (! $requestTypeHandled) {
                $requestResult = $getRequestType($extension);

                if ($requestResult instanceof Type) {
                    $requestTypeHandled = true;

                    $this->scope->objectsHandlingRequestBody[$node] = true;
                    $this->scope->route?->addRequestBodyType($requestResult);
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

    public function handleOperationExtensions(Operation $operation, Route $route, Scope $scope): Operation
    {
        foreach ($this->getExtensions(OperationExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $extensionResult = $extension->handle($operation, $route, $scope);

            if ($extensionResult !== null) {
                $operation = $extensionResult;
            }
        }

        return $operation;
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function handlePropertyTypeExtensions(PhpClass $phpClass, string $propertyName): ?Type
    {
        foreach ($this->getExtensions(ClassExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $propertyType = $extension->getPropertyType($phpClass, $propertyName);

            if ($propertyType !== null) {
                return $propertyType;
            }
        }

        return null;
    }

    public function handleThrowExtensions(ThrowContext $throw): ?Type
    {
        foreach ($this->getExtensions(ThrowExtension::class) as $extensionClass) {
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
        foreach ($this->getExtensions(TypeScriptExportExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $returnedType = $extension->handle($phpClass, $type);

            if ($returnedType !== null) {
                $type = $returnedType;
            }
        }

        return $type;
    }
}
