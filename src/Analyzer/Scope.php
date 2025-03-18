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
use AutoDoc\Exceptions\AutoDocException;
use AutoDoc\ExtensionHandler;
use AutoDoc\Route;
use Exception;
use PhpParser\Comment;
use PhpParser\Node;
use Throwable;

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

                    return $phpDoc->resolveVarType($variableName);
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
                'bool', 'true', 'false' => new BoolType,
                'array', 'iterable' => new ArrayType,
                'object' => new ObjectType,
                'null' => new NullType,
                'void' => new VoidType,
                default => new UnknownType,
            };
        }

        if ($node instanceof Node\Expr\Variable) {
            return $this->getVariableType($node)?->unwrapType() ?? new UnknownType;
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $returnType = $this->getReturnTypeFromExtensions($node);

            if ($returnType !== null) {
                return $returnType->unwrapType();
            }

            $varType = $this->resolveType($node->var);

            if (isset($varType->className) && $node->name instanceof Node\Identifier) {
                /** @var class-string<object> */
                $className = $varType->className;

                $phpClassMethod = $this->getPhpClassInDeeperScope($className)->getMethod(
                    name: $node->name->name,
                    args: PhpFunctionArgument::list($node->args, scope: $this),
                );

                return $phpClassMethod->getReturnType()->unwrapType();
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $returnType = $this->getReturnTypeFromExtensions($node);

            if ($returnType !== null) {
                return $returnType->unwrapType();
            }

            if ($node->name instanceof Node\Name) {
                $function = new PhpFunction(
                    nameOrReflection: $node->name->name,
                    scope: $this,
                    args: PhpFunctionArgument::list($node->args, scope: $this),
                );

                return $function->getReturnType()->unwrapType();
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $returnType = $this->getReturnTypeFromExtensions($node);

            if ($returnType !== null) {
                return $returnType->unwrapType();
            }

            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $className = $this->getResolvedClassName($node->class);

                if ($className) {
                    $phpClassMethod = $this->getPhpClassInDeeperScope($className)->getMethod(
                        name: $node->name->name,
                        args: PhpFunctionArgument::list($node->args, scope: $this),
                    );

                    return $phpClassMethod->getReturnType()->unwrapType();
                }
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\Array_) {
            $arrayType = new ArrayType;
            $itemTypes = [];

            foreach ($node->items as $arrayItemNode) {
                if ($arrayItemNode->unpack) {
                    try {
                        $destructuredArrayType = $this->resolveType($arrayItemNode->value);

                        if (! ($destructuredArrayType instanceof ArrayType)) {
                            throw new Exception('Unexpected ' . PhpClass::basename($destructuredArrayType::class));
                        }

                        /** @var Node\ArrayItem[] */
                        $itemsToUnpack = [];

                        if ($destructuredArrayType->shape) {
                            foreach ($destructuredArrayType->shape as $key => $valueType) {
                                if (! ($valueType instanceof UnresolvedParserNodeType)) {
                                    throw new Exception('Unexpected ' . PhpClass::basename($valueType::class));
                                }

                                if (! ($valueType->node instanceof Node\Expr)) {
                                    throw new Exception('Unexpected ' . $valueType->node::class);
                                }

                                $itemsToUnpack[] = new Node\ArrayItem(
                                    value: $valueType->node,
                                    key: $key ? (is_int($key) ? new Node\Scalar\Int_($key) : new Node\Scalar\String_($key)) : null,
                                );
                            }

                        } else {
                            if (! $destructuredArrayType->itemType) {
                                throw new Exception('Unknown array item type');
                            }

                            if ($destructuredArrayType->itemType instanceof UnionType) {
                                $destructuredItemTypes = $destructuredArrayType->itemType->types;
                            } else {
                                $destructuredItemTypes = [$destructuredArrayType->itemType];
                            }

                            foreach ($destructuredItemTypes as $destructuredItemType) {
                                if (! ($destructuredItemType instanceof UnresolvedParserNodeType)) {
                                    throw new Exception('Unexpected ' . PhpClass::basename($destructuredItemType::class));
                                }

                                if (! ($destructuredItemType->node instanceof Node\Expr)) {
                                    throw new Exception('Unexpected ' . $destructuredItemType->node::class);
                                }

                                $itemsToUnpack[] = new Node\ArrayItem($destructuredItemType->node);
                            }
                        }

                    } catch (Throwable $exception) {
                        if ($this->isDebugModeEnabled()) {
                            throw new AutoDocException('Error unpacking value: ', $exception);
                        }

                        // If we can't resolve all array elements, rather than showing incomplete structure,
                        // do not show it at all to prevent confusion.
                        return new ArrayType;
                    }

                } else {
                    $itemsToUnpack = [$arrayItemNode];
                }

                foreach ($itemsToUnpack as $item) {
                    $comments = $item->getComments();
                    $description = null;
                    $exampleValues = null;
                    $itemType = null;

                    foreach ($comments as $comment) {
                        if ($comment instanceof Comment\Doc) {
                            $phpDoc = new PhpDoc($comment->getText(), $this);

                            $description = $phpDoc->getText();
                            $exampleValues = $phpDoc->getExampleValues();

                            foreach ($phpDoc->getVarTags() as $var) {
                                [$varName, $varType] = $var;

                                if (! $varName) {
                                    $itemType = $varType;
                                    break;
                                }
                            }
                        }
                    }

                    if ($itemType) {
                        $itemType->description = $itemType->description ?: $description;
                        $itemType->fallbackType = new UnresolvedParserNodeType($item->value, $this, $description);

                    } else {
                        $itemType = new UnresolvedParserNodeType($item->value, $this, $description);
                    }

                    $itemType->examples = $itemType->examples ?: $exampleValues ?: null;

                    $itemTypes[] = $itemType;

                    if ($item->key instanceof Node\Scalar\String_
                        || $item->key instanceof Node\Scalar\Int_
                    ) {
                        $arrayType->shape[$item->key->value] = $itemType;
                    }
                }
            }

            if (! $arrayType->shape) {
                $itemTypesUnion = new UnionType($itemTypes);

                $arrayType->itemType = $itemTypesUnion->unwrapType();
            }

            return $arrayType;
        }

        if ($node instanceof Node\ArrayItem || $node instanceof Node\Arg) {
            return $this->resolveType($node->value);
        }

        if ($node instanceof Node\Expr\PropertyFetch) {
            $varType = $this->resolveType($node->var);

            if ($varType instanceof ObjectType) {
                $propertyName = $this->getRawValueFromNode($node->name);

                if ($propertyName) {
                    if ($varType->className && is_string($propertyName)) {
                        $varClass = $this->getPhpClass($varType->className);
                        $propertyType = $this->getPropertyTypeFromExtensions($varClass, $propertyName);

                        if ($propertyType) {
                            return $propertyType->unwrapType();
                        }
                    }

                    $propertyType = $varType->typeToDisplay->properties[$propertyName]
                        ?? $varType->properties[$propertyName]
                        ?? null;

                    $propertyType = $propertyType?->unwrapType() ?? new UnknownType;

                    if ($propertyType instanceof UnknownType && isset($varClass) && is_string($propertyName)) {
                        $mixinTag = $varClass->getPhpDoc()?->getMixinTag();

                        if ($mixinTag) {
                            $mixinClass = $this->getPhpClassInDeeperScope($mixinTag->className);
                            $propertyType = $this->getPropertyTypeFromExtensions($mixinClass, $propertyName);

                            if ($propertyType) {
                                return $propertyType->unwrapType();
                            }

                            return $mixinClass->getProperty($propertyName)?->unwrapType() ?? new UnknownType;
                        }
                    }

                    return $propertyType;
                }
            }

            return new UnknownType;
        }

        if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim) {
            $varType = $this->resolveType($node->var);
            $key = $this->getRawValueFromNode($node->dim);
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

            return $type?->unwrapType() ?? new UnknownType;
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
                return new BoolType;
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
            $varType = $this->getVariableType($node)?->unwrapType();

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
