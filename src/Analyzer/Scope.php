<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\UnresolvedType;
use AutoDoc\DataTypes\VoidType;
use AutoDoc\ExtensionHandler;
use AutoDoc\Route;
use PhpParser\Comment;
use PhpParser\Node;

class Scope
{
    public function __construct(
        public Config $config,
        public int $depth = 0,
        public ?Route $route = null,

        /**
         * @var ?class-string
         */
        public ?string $className = null,
        public ?string $methodName = null,

        /**
         * @var array<string, ?UnresolvedType>
         */
        public array $constructorTemplateTypes = [],

        /**
         * @var array<PhpFunctionArgument>
         */
        public array $constructorArgs = [],
    ) {}


    public function resolveType(Node $node, ?string $variableName = null, bool $isFinalResponse = false): Type
    {
        $comments = $node->getComments();

        if ($comments) {
            foreach ($comments as $comment) {
                if ($comment instanceof Comment\Doc) {
                    $phpDoc = new PhpDoc($comment->getText(), $this);

                    $typeFromPhpDoc = $phpDoc->resolveVarType($variableName);

                    if (! ($typeFromPhpDoc instanceof UnknownType)) {
                        return $typeFromPhpDoc;
                    }
                }
            }
        }

        if ($node instanceof Node\Name) {
            $className = $this->getResolvedClassName($node);

            if (! $className) {
                return new ObjectType;
            }

            $phpClass = $this->getPhpClassInDeeperScope($className);

            if (! $phpClass->exists()) {
                return new ObjectType;
            }

            return $phpClass->resolveType();
        }

        if ($node instanceof Node\Identifier) {
            return match ($node->name) {
                'int' => new IntegerType,
                'float' => new FloatType,
                'string' => new StringType,
                'true' => new BoolType(true),
                'false' => new BoolType(false),
                'bool', 'boolean' => new BoolType,
                'array', 'iterable' => new ArrayType,
                'object' => new ObjectType,
                'null' => new NullType,
                'void' => new VoidType,
                default => new UnknownType,
            };
        }

        if ($node instanceof Node\Expr\Variable) {
            return $this->getVariableType($node)?->unwrapType($this->config) ?? new UnknownType;
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            if ($node instanceof Node\Expr\MethodCall) {
                $returnType = $this->getReturnTypeFromExtensions($node);

                if ($returnType !== null) {
                    return $returnType->unwrapType($this->config);
                }
            }

            $methodName = (string) $this->getRawValueFromNode($node->name);
            $varType = $this->resolveType($node->var);

            $getMethodReturnType = function (ObjectType $varType) use ($methodName, $node) {
                if (isset($varType->className)) {
                    /** @var class-string<object> */
                    $className = $varType->className;

                    $phpClassMethod = $this->getPhpClassInDeeperScope($className)->getMethod(
                        name: $methodName,
                        args: PhpFunctionArgument::list($node->args, scope: $this),
                    );

                    return $phpClassMethod->getReturnType()->unwrapType($this->config);
                }

                return new UnknownType;
            };

            if ($varType instanceof ObjectType) {
                return $getMethodReturnType($varType);

            } else if ($varType instanceof UnionType) {
                $returnTypes = [];

                foreach ($varType->types as $type) {
                    if ($type instanceof ObjectType) {
                        $returnType = $getMethodReturnType($type);

                        if (! ($returnType instanceof UnknownType)) {
                            $returnTypes[] = $returnType;
                        }

                    } else if ($type instanceof NullType && $node instanceof Node\Expr\NullsafeMethodCall) {
                        $returnTypes[] = $type;
                    }
                }

                return (new UnionType($returnTypes))->unwrapType($this->config);
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $returnType = $this->getReturnTypeFromExtensions($node);

            if ($returnType !== null) {
                return $returnType->unwrapType($this->config);
            }

            if ($node->name instanceof Node\Name) {
                $function = new PhpFunction(
                    nameOrReflection: $node->name->name,
                    scope: $this,
                    args: PhpFunctionArgument::list($node->args, scope: $this),
                );

                return $function->getReturnType()->unwrapType($this->config);
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $returnType = $this->getReturnTypeFromExtensions($node);

            if ($returnType !== null) {
                return $returnType->unwrapType($this->config);
            }

            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $className = $this->getResolvedClassName($node->class);

                if ($className) {
                    $phpClassMethod = $this->getPhpClassInDeeperScope($className)->getMethod(
                        name: $node->name->name,
                        args: PhpFunctionArgument::list($node->args, scope: $this),
                    );

                    return $phpClassMethod->getReturnType()->unwrapType($this->config);
                }
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\Array_) {
            return (new PhpArray(scope: $this, node: $node))->resolveType();
        }

        if ($node instanceof Node\ArrayItem || $node instanceof Node\Arg) {
            return $this->resolveType($node->value);
        }

        if ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) {
            $varType = $this->resolveType($node->var);

            $getPropertyType = function (ObjectType $varType, string $propertyName) use ($node) {
                if ($varType->className) {
                    $varClass = $this->getPhpClass($varType->className);
                    $propertyType = $this->getPropertyTypeFromExtensions($varClass, $propertyName);

                    if ($propertyType) {
                        return $propertyType->unwrapType($this->config);
                    }
                }

                $propertyType = $varType->typeToDisplay->properties[$propertyName]
                    ?? $varType->properties[$propertyName]
                    ?? null;

                $propertyType = $propertyType?->unwrapType($this->config) ?? new UnknownType;

                if ($propertyType instanceof UnknownType && isset($varClass)) {
                    $allowPrivateAndProtected = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';

                    $propertyType = $varClass->getProperty($propertyName, $allowPrivateAndProtected)?->unwrapType($this->config) ?? new UnknownType;

                    if ($propertyType instanceof UnknownType) {
                        $mixinTag = $varClass->getPhpDoc()?->getMixinTag();

                        if ($mixinTag) {
                            $mixinClass = $this->getPhpClassInDeeperScope($mixinTag->className);
                            $propertyType = $this->getPropertyTypeFromExtensions($mixinClass, $propertyName);

                            if ($propertyType) {
                                return $propertyType->unwrapType($this->config);
                            }

                            return $mixinClass->getProperty($propertyName)?->unwrapType($this->config) ?? new UnknownType;
                        }
                    }
                }

                return $propertyType;
            };

            $propertyName = (string) $this->getRawValueFromNode($node->name);

            if (! $propertyName) {
                return new UnknownType;
            }

            if ($varType instanceof ObjectType) {
                return $getPropertyType($varType, $propertyName);

            } else if ($varType instanceof UnionType) {
                $types = [];

                foreach ($varType->types as $type) {
                    if ($type instanceof ObjectType) {
                        $propType = $getPropertyType($type, $propertyName);

                        if (! ($propType instanceof UnknownType)) {
                            $types[] = $propType;
                        }

                    } else if ($type instanceof NullType && $node instanceof Node\Expr\NullsafePropertyFetch) {
                        $types[] = $type;
                    }
                }

                return (new UnionType($types))->unwrapType($this->config);
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim) {
            $varType = $this->resolveType($node->var);
            $key = $this->getRawValueFromNode($node->dim);

            $getArrayItemType = function ($varType) use ($key) {
                $type = null;

                if ($varType instanceof ArrayType) {
                    $type = $varType->shape[$key]
                        ?? $varType->itemType
                        ?? null;

                } else if ($varType instanceof ObjectType) {
                    $type = $varType->typeToDisplay->shape[$key]
                        ?? $varType->typeToDisplay->properties[$key]
                        ?? $varType->typeToDisplay->itemType
                        ?? null;
                }

                return $type?->unwrapType($this->config) ?? new UnknownType;
            };

            if ($varType instanceof UnionType) {
                $types = [];

                foreach ($varType->types as $type) {
                    $types[] = $getArrayItemType($type);
                }

                return (new UnionType($types))->unwrapType($this->config);
            }

            return $getArrayItemType($varType);
        }

        if ($node instanceof Node\Scalar\String_) {
            return new StringType($node->value);
        }

        if ($node instanceof Node\Scalar\Int_) {
            return new IntegerType($node->value);
        }

        if ($node instanceof Node\Scalar\Float_) {
            return new FloatType($node->value);
        }

        if ($node instanceof Node\Expr\UnaryMinus) {
            $numberType = $this->resolveType($node->expr);

            if ($numberType instanceof IntegerType
                || $numberType instanceof NumberType
                || $numberType instanceof FloatType
            ) {
                if (! is_array($numberType->value)
                    && ! is_null($numberType->value)
                ) {
                    $numberType->value = -$numberType->value;

                    return $numberType;
                }
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\Ternary) {
            return new UnionType([
                $this->resolveType($node->if ?? $node->cond),
                $this->resolveType($node->else),
            ]);
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            $keyword = $node->name->toString();

            if ($keyword === 'null') {
                return new NullType;
            }

            if ($keyword === 'true' || $keyword === 'false') {
                return new BoolType($keyword === 'true');
            }
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            if ($node->name instanceof Node\Identifier && $node->class instanceof Node\Name) {

                $className = $this->getResolvedClassName($node->class);
                $constantName = $node->name->name;

                if ($constantName === 'class') {
                    return new ClassStringType(className: $className);
                }

                if ($className) {
                    return $this->getPhpClassInDeeperScope($className)->resolveConstantType($constantName);
                }
            }
        }

        if ($node instanceof Node\Expr\New_) {
            $className = null;

            if ($node->class instanceof Node\Name) {
                $className = $this->getResolvedClassName($node->class);

            } else {
                $value = $this->getRawValueFromNode($node->class);

                if (is_string($value)) {
                    $className = PhpClass::removeLeadingBackslash($value);
                }
            }

            if (! $className) {
                return new ObjectType;
            }

            $phpClass = $this->getPhpClassInDeeperScope($className);

            $templateTypes = $phpClass->getPhpDoc()?->getTemplateTypes();

            $phpClass->scope->constructorArgs = PhpFunctionArgument::list($node->args, scope: $this);
            $phpClass->isFinalResponse = $isFinalResponse;

            if ($templateTypes) {
                $constructor = $phpClass->getMethod('__construct', $phpClass->scope->constructorArgs)->getPhpFunction();

                if ($constructor) {
                    $phpClass->scope->constructorTemplateTypes = $constructor->fillTemplateTypesFromParameters();
                }
            }

            return $phpClass->resolveType();
        }

        if ($node instanceof Node\Expr\Cast\String_) {
            return new StringType;
        }

        if ($node instanceof Node\Expr\Cast\Bool_) {
            return new BoolType;
        }

        if ($node instanceof Node\Expr\Cast\Array_) {
            return new ArrayType;
        }

        if ($node instanceof Node\Expr\Cast\Double) {
            return new FloatType;
        }

        if ($node instanceof Node\Expr\Cast\Int_) {
            return new IntegerType;
        }

        if ($node instanceof Node\Expr\Cast\Object_) {
            if ($node->expr instanceof Node\Expr\Array_) {
                return new ObjectType(typeToDisplay: new UnresolvedParserNodeType($node->expr, $this));
            }

            return new ObjectType;
        }

        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            return new UnionType([
                $this->resolveType($node->left),
                $this->resolveType($node->right),
            ]);
        }

        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $leftString = $this->getRawValueFromNode($node->left);
            $rightString = $this->getRawValueFromNode($node->right);

            if ($leftString === null || $rightString === null) {
                return new StringType;
            }

            return new StringType($leftString . $rightString);
        }

        if ($node instanceof Node\Expr\BinaryOp\Plus
            || $node instanceof Node\Expr\BinaryOp\Minus
            || $node instanceof Node\Expr\BinaryOp\Mul
            || $node instanceof Node\Expr\BinaryOp\Div
            || $node instanceof Node\Expr\BinaryOp\Mod
            || $node instanceof Node\Expr\BinaryOp\Pow
        ) {
            return new NumberType;
        }

        return new UnknownType;
    }


    public function getRawValueFromNode(Node $node): int|string|float|null
    {
        if ($node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
        ) {
            return $node->value;
        }

        if ($node instanceof Node\Identifier) {
            return $node->name;
        }

        if ($node instanceof Node\Expr\Variable) {
            $varType = $this->getVariableType($node)?->unwrapType($this->config);

            if ($varType instanceof StringType
                || $varType instanceof IntegerType
            ) {
                if (! is_array($varType->value)) {
                    return $varType->value;
                }
            }
        }

        return null;
    }


    /**
     * @param Comment[] $comments
     */
    public function assignVariable(Node\Expr\Variable $varNode, Node|Type $valueNode, array $comments = [], int $depth = 0): void
    {
        if ($valueNode instanceof Node) {
            $valueNode->setAttribute('comments', array_merge(
                $comments,
                $varNode->getComments(),
                $valueNode->getComments(),
            ));

            $type = new UnresolvedParserNodeType(node: $valueNode, scope: $this);

        } else {
            $type = $valueNode;
        }

        if (! is_string($varNode->name)) {
            return;
        }

        /** @var int */
        $line = $varNode->getAttribute('startLine', 0);

        PhpVariable::assign($varNode->name, $line, $type, $this, $depth);
    }

    public function getVariableType(Node\Expr\Variable $varNode): ?Type
    {
        return PhpVariable::find($varNode, $this);
    }


    /**
     * @return ?class-string
     */
    public function getResolvedClassName(string|Node\Name $name): ?string
    {
        if ($name instanceof Node\Name) {
            if ($name instanceof Node\Name\FullyQualified) {
                return PhpClass::removeLeadingBackslash($name->name);
            }

            return $this->getResolvedClassName($name->name);
        }

        if ($name === 'self' || $name === 'static') {
            return $this->className;
        }

        if (str_starts_with($name, '\\')) {
            return PhpClass::removeLeadingBackslash($name);
        }

        if (! $this->className) {
            return null;
        }

        $nameResolver = $this->getCurrentPhpClass()?->getNameResolver();

        return $nameResolver?->getResolvedClassName($name);
    }


    /**
     * @param ?class-string $className
     */
    public function createChildScope(?string $className = null, ?string $methodName = null): Scope
    {
        return new Scope(
            config: $this->config,
            depth: $this->depth + 1,
            route: $this->route,
            className: $className,
            methodName: $methodName,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return PhpClass<T>
     */
    public function getPhpClassInDeeperScope(string $className): PhpClass
    {
        return $this->createChildScope($className)->getPhpClass($className);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return PhpClass<T>
     */
    public function getPhpClass(string $className): PhpClass
    {
        $className = PhpClass::removeLeadingBackslash($className);

        /** @var PhpClass<T>|null */
        $phpClass = PhpClass::$cache[$className] ?? null;

        if ($phpClass) {
            $phpClass->scope = $this;

            return $phpClass;
        }

        /** @var PhpClass<T> */
        $phpClass = new PhpClass(
            className: $className,
            scope: $this,
        );

        PhpClass::$cache[$className] = $phpClass;

        return $phpClass;
    }


    /**
     * @return ?PhpClass<object>
     */
    public function getCurrentPhpClass(): ?PhpClass
    {
        if (! $this->className) {
            return null;
        }

        return $this->getPhpClass($this->className);
    }


    public function handleThrowExtensions(Node\Expr $expr): ?Type
    {
        return (new ExtensionHandler($this))->handleThrowExtensions($expr);
    }

    /**
     * @param Node\Expr\MethodCall|Node\Expr\FuncCall|Node\Expr\StaticCall|PhpClass<object> $classOrExpr
     */
    public function handleExpectedRequestTypeFromExtensions(Node\Expr\MethodCall|Node\Expr\FuncCall|Node\Expr\StaticCall|PhpClass $classOrExpr): void
    {
        (new ExtensionHandler($this))->handleTypeExtensions($classOrExpr, getReturnType: false);
    }

    /**
     * @param Node\Expr\MethodCall|Node\Expr\FuncCall|Node\Expr\StaticCall|PhpClass<object> $classOrExpr
     */
    public function getReturnTypeFromExtensions(Node\Expr\MethodCall|Node\Expr\FuncCall|Node\Expr\StaticCall|PhpClass $classOrExpr): ?Type
    {
        return (new ExtensionHandler($this))->handleTypeExtensions($classOrExpr);
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getPropertyTypeFromExtensions(PhpClass $phpClass, string $propertyName): ?Type
    {
        return (new ExtensionHandler($this))->handlePropertyTypeExtensions($phpClass, $propertyName);
    }


    public function isDebugModeEnabled(): bool
    {
        return $this->config->data['debug']['enabled'];
    }
}
