<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\Scope;
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
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;

class ExtensionHandler
{
    public function __construct(
        private Scope $scope,
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
            self::$extensions = [];

            self::$extensions[FuncCallExtension::class][] = ArrayFuncCall::class;

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
     * @param MethodCall|FuncCall|StaticCall|PhpClass<object> $classOrExpr
     *
     * @return ($getReturnType is true ? Type|null : null)
     */
    public function handleTypeExtensions(MethodCall|FuncCall|StaticCall|PhpClass $classOrExpr, bool $getReturnType = true): ?Type
    {
        $requestTypeHandled = isset($this->scope->objectsHandlingRequestBody[$classOrExpr]);
        $returnTypeHandled = ! $getReturnType;
        $returnType = null;

        if ($classOrExpr instanceof MethodCall) {
            $extensionTypeClass = MethodCallExtension::class;

        } else if ($classOrExpr instanceof FuncCall) {
            $extensionTypeClass = FuncCallExtension::class;

        } else if ($classOrExpr instanceof StaticCall) {
            $extensionTypeClass = StaticCallExtension::class;

        } else {
            $extensionTypeClass = ClassExtension::class;
        }

        foreach ($this->getExtensions($extensionTypeClass) as $extensionClass) {
            $extension = new $extensionClass;

            if (! $requestTypeHandled) {
                $requestType = $extension->getRequestType($classOrExpr, $this->scope);

                if ($requestType) {
                    $requestTypeHandled = true;

                    $this->scope->objectsHandlingRequestBody[$classOrExpr] = true;
                    $this->scope->route?->addRequestBodyType($requestType);
                }
            }

            if (! $returnTypeHandled) {
                $returnType = $extension->getReturnType($classOrExpr, $this->scope);

                if ($returnType) {
                    $returnTypeHandled = true;
                }
            }

            if ($requestTypeHandled && $returnTypeHandled) {
                break;
            }
        }

        return $returnType;
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

    public function handleThrowExtensions(Node\Expr $expr): ?Type
    {
        foreach ($this->getExtensions(ThrowExtension::class) as $extensionClass) {
            $extension = new $extensionClass;

            $returnedType = $extension->getReturnType($expr, $this->scope);

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
