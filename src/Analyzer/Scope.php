<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Analyzer\Flow\BranchBreakout;
use AutoDoc\Analyzer\Flow\ScopeEventLog;
use AutoDoc\Analyzer\Traits\HandlesExtensions;
use AutoDoc\Analyzer\Traits\StoresVariables;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\UnresolvedVariableType;
use AutoDoc\DataTypes\VoidType;
use AutoDoc\Exceptions\AutoDocException;
use AutoDoc\Route;
use PhpParser\Comment;
use PhpParser\Node;
use ReflectionFunction;
use Throwable;
use WeakMap;

class Scope
{
    use HandlesExtensions;
    use StoresVariables;

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
         * @var array<string, ?Type>
         */
        public array $constructorTemplateTypes = [],
    ) {
        $this->constructorArgs = new ArgumentList($this);
        $this->eventLog = new ScopeEventLog;
        $this->classesHandlingRequestBody = new WeakMap;
        $this->nodesWithHandledSideEffects = new WeakMap;
        $this->nodesBeingResolved = new WeakMap;
    }

    public ArgumentList $constructorArgs;


    public ?Node $callerNode = null;

    /**
     * Classes whose request body has already been captured (form-request style
     * class extensions), so a repeated resolution doesn't record it twice.
     *
     * @internal
     * @var WeakMap<object, true>
     */
    public WeakMap $classesHandlingRequestBody;

    /**
     * Call nodes whose side-effect hook has already run this pass, so a node
     * visited both as a statement and during return-type resolution fires once.
     *
     * @internal
     * @var WeakMap<Node, true>
     */
    public WeakMap $nodesWithHandledSideEffects;

    /**
     * Suppresses extension side effects (request-body capture + variable
     * mutation) during return-type peeks.
     *
     * Only set through {@see withoutSideEffects()}.
     * @internal
     */
    private bool $suppressSideEffects = false;

    /**
     * Cache for resolved variable types, keyed by "varName:filePos".
     *
     * @internal
     * @var array<string, Type>
     */
    public array $resolvedVariables = [];

    /**
     * @internal
     * @var WeakMap<Node, true>
     */
    public WeakMap $nodesBeingResolved;

    private ?BranchBreakout $branchBreakout = null;

    /**
     * Shared per scope so the body-entry breakout scan and every condition's
     * exit check reuse one per-statement cache.
     */
    public function getBranchBreakout(): BranchBreakout
    {
        return $this->branchBreakout ??= new BranchBreakout($this);
    }

    public function resolveType(Node $node, ?string $variableName = null, bool $isFinalResponse = false): Type
    {
        if (isset($this->nodesBeingResolved[$node])) {
            return new UnknownType;
        }

        $this->nodesBeingResolved[$node] = true;

        try {
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
                    'callable' => new CallableType,
                    'null' => new NullType,
                    'void' => new VoidType,
                    'never' => new NeverType,
                    default => new UnknownType,
                };
            }

            if ($node instanceof Node\NullableType) {
                return new UnionType([
                    $this->resolveType($node->type),
                    new NullType,
                ])->unwrapType($this->config);
            }

            if ($node instanceof Node\UnionType) {
                return new UnionType(
                    array_map($this->resolveType(...), $node->types),
                )->unwrapType($this->config);
            }

            if ($node instanceof Node\IntersectionType) {
                return new IntersectionType(
                    array_map($this->resolveType(...), $node->types),
                )->unwrapType($this->config);
            }

            if ($node instanceof Node\Expr\Variable) {
                return $this->getVariableType($node)?->unwrapType($this->config) ?? new UnknownType;
            }

            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                $context = new MethodCallContext(node: $node, scope: $this);

                $this->runSideEffectExtensions($context);

                $returnType = $this->getReturnTypeFromMethodCallExtensions($context);

                if ($returnType !== null) {
                    return $returnType->unwrapType($this->config);
                }

                $varType = $context->getVarType();

                $getMethodReturnType = function (ObjectType|ArrayType $varType) use ($context) {
                    if (isset($varType->className)) {
                        $phpClassMethod = $this->getPhpClassInDeeperScope($varType->className)->getMethod(
                            name: $context->methodName,
                            args: $context->argTypes,
                        );

                        return $phpClassMethod->getReturnType()->unwrapType($this->config);
                    }

                    return new UnknownType;
                };

                if ($varType instanceof ObjectType || $varType instanceof ArrayType) {
                    return $getMethodReturnType($varType);

                } else if ($varType instanceof UnionType) {
                    $returnTypes = [];

                    foreach ($varType->types as $type) {
                        if ($type instanceof ObjectType || $type instanceof ArrayType) {
                            $returnType = $getMethodReturnType($type);

                            if (! ($returnType instanceof UnknownType)) {
                                $returnTypes[] = $returnType;
                            }

                        } else if ($type instanceof NullType && $node instanceof Node\Expr\NullsafeMethodCall) {
                            $returnTypes[] = $type;
                        }
                    }

                    return new UnionType($returnTypes)->unwrapType($this->config);
                }

                return new UnknownType;
            }

            if ($node instanceof Node\Expr\FuncCall) {
                $context = new FuncCallContext(node: $node, scope: $this);

                $this->runSideEffectExtensions($context);

                $returnType = $this->getReturnTypeFromFuncCallExtensions($context);

                if ($returnType !== null) {
                    return $returnType->unwrapType($this->config);
                }

                if ($context->functionName !== null) {
                    try {
                        $phpCallable = new PhpCallable(
                            scope: $this,
                            reflection: new ReflectionFunction($context->functionName),
                            args: $context->argTypes,
                        );

                        return $phpCallable->getReturnType()->unwrapType($this->config);

                    } catch (Throwable $exception) {
                        if ($this->isDebugModeEnabled()) {
                            throw new AutoDocException('Error resolving function "' . $context->functionName . '": ', $exception);
                        }
                    }

                } else {
                    $functionNodeType = $this->resolveType($node->name);

                    if ($functionNodeType instanceof CallableType) {
                        return $functionNodeType->getReturnType($context->argTypes, $node);
                    }
                }
            }

            if ($node instanceof Node\Expr\StaticCall) {
                $context = new StaticCallContext(node: $node, scope: $this);

                $this->runSideEffectExtensions($context);

                $returnType = $this->getReturnTypeFromStaticCallExtensions($context);

                if ($returnType !== null) {
                    return $returnType->unwrapType($this->config);
                }

                if ($context->className && $node->name instanceof Node\Identifier) {
                    $phpClassMethod = $this->getPhpClassInDeeperScope($context->className)->getMethod(
                        name: $context->methodName,
                        args: $context->argTypes,
                    );

                    return $phpClassMethod->getReturnType()->unwrapType($this->config);
                }

                return new UnknownType;
            }

            if ($node instanceof Node\Expr\Array_) {
                return new PhpArray(scope: $this, node: $node)->resolveType();
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

                    $displayObjectType = $varType->typeToDisplay instanceof ObjectType ? $varType->typeToDisplay : null;

                    $propertyType = $displayObjectType?->properties[$propertyName]
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
                    /** @var list<Type> $types */
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

                    return new UnionType($types)->unwrapType($this->config);
                }

                return new UnknownType;
            }

            if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim) {
                return $this->resolveArrayDimFetchChain($node);
            }

            if ($node instanceof Node\Scalar\String_) {
                return new StringType($node->value);
            }

            if ($node instanceof Node\Scalar\InterpolatedString) {
                return new StringType;
            }

            if ($node instanceof Node\Scalar\Int_) {
                return new IntegerType($node->value);
            }

            if ($node instanceof Node\Scalar\Float_) {
                return new FloatType($node->value);
            }

            // The negated literal is recomputed onto a fresh type so the operand
            // is never mutated in place, and any description/examples it carried
            // (which describe the original value) are dropped.
            if ($node instanceof Node\Expr\UnaryMinus) {
                $numberType = $this->resolveType($node->expr);

                if ($numberType instanceof IntegerType && is_int($numberType->value)) {
                    return new IntegerType(-$numberType->value);
                }

                if ($numberType instanceof FloatType && is_float($numberType->value)) {
                    return new FloatType(-$numberType->value);
                }

                if ($numberType instanceof NumberType
                    && (is_int($numberType->value) || is_float($numberType->value))
                ) {
                    return new NumberType(-$numberType->value);
                }

                return new NumberType;
            }

            if ($node instanceof Node\Expr\UnaryPlus) {
                $numberType = $this->resolveType($node->expr);

                if ($numberType instanceof IntegerType
                    || $numberType instanceof NumberType
                    || $numberType instanceof FloatType
                ) {
                    return $numberType;
                }

                return new NumberType;
            }

            // Inc/dec change the operand, so the literal value is dropped, but the
            // numeric kind (int stays int, float stays float) is preserved.
            if ($node instanceof Node\Expr\PreInc
                || $node instanceof Node\Expr\PostInc
                || $node instanceof Node\Expr\PreDec
                || $node instanceof Node\Expr\PostDec
            ) {
                $numberType = $this->resolveType($node->var);

                if ($numberType instanceof IntegerType) {
                    return new IntegerType;
                }

                if ($numberType instanceof FloatType) {
                    return new FloatType;
                }

                return new NumberType;
            }

            if ($node instanceof Node\Expr\Ternary) {
                // Short ternary (`$a ?: $b`) yields the left operand only when it is truthy,
                // so null can't leak through it.
                if ($node->if === null) {
                    return new UnionType([
                        $this->resolveType($node->cond)->removeNull($this->config),
                        $this->resolveType($node->else),
                    ]);
                }

                return new UnionType([
                    $this->resolveType($node->if),
                    $this->resolveType($node->else),
                ]);
            }

            if ($node instanceof Node\Expr\Match_) {
                $armTypes = [];

                foreach ($node->arms as $arm) {
                    $armTypes[] = $this->resolveType($arm->body);
                }

                return new UnionType($armTypes)->unwrapType($this->config);
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

                $phpClass->isFinalResponse = $isFinalResponse;
                $phpClass->scope->constructorArgs = ArgumentList::fromArgNodes($node->args, $this);

                $templateTypes = $phpClass->getPhpDoc()?->getTemplateTypes();

                if ($templateTypes) {
                    $constructor = $phpClass->getMethod('__construct', $phpClass->scope->constructorArgs);
                    $phpClass->scope->constructorTemplateTypes = $constructor->fillTemplateTypesFromParameters();
                }

                return $phpClass->resolveType();
            }

            if ($node instanceof Node\Expr\Cast\String_) {
                return new StringType;
            }

            if ($node instanceof Node\Expr\Cast\Bool_) {
                return new BoolType;
            }

            if ($node instanceof Node\Expr\BooleanNot) {
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
                if ($node->expr instanceof Node\Expr\Array_ && $node->expr->items) {
                    return new ObjectType(typeToDisplay: new UnresolvedParserNodeType($node->expr, $this));
                }

                return new ObjectType;
            }

            if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
                if ($node->right instanceof Node\Expr\Throw_) {
                    return $this->resolveType($node->left)->removeNull($this->config);
                }

                return new UnionType([
                    $this->resolveType($node->left)->removeNull($this->config),
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

            if ($node instanceof Node\Expr\BinaryOp\Equal
                || $node instanceof Node\Expr\BinaryOp\NotEqual
                || $node instanceof Node\Expr\BinaryOp\Identical
                || $node instanceof Node\Expr\BinaryOp\NotIdentical
                || $node instanceof Node\Expr\BinaryOp\Greater
                || $node instanceof Node\Expr\BinaryOp\GreaterOrEqual
                || $node instanceof Node\Expr\BinaryOp\Smaller
                || $node instanceof Node\Expr\BinaryOp\SmallerOrEqual
                || $node instanceof Node\Expr\BinaryOp\BooleanAnd
                || $node instanceof Node\Expr\BinaryOp\BooleanOr
                || $node instanceof Node\Expr\BinaryOp\LogicalAnd
                || $node instanceof Node\Expr\BinaryOp\LogicalOr
                || $node instanceof Node\Expr\BinaryOp\LogicalXor
                || $node instanceof Node\Expr\Instanceof_
                || $node instanceof Node\Expr\Isset_
                || $node instanceof Node\Expr\Empty_
            ) {
                return new BoolType;
            }

            if ($node instanceof Node\Expr\BinaryOp\Spaceship
                || $node instanceof Node\Expr\BinaryOp\BitwiseAnd
                || $node instanceof Node\Expr\BinaryOp\BitwiseOr
                || $node instanceof Node\Expr\BinaryOp\BitwiseXor
                || $node instanceof Node\Expr\BinaryOp\ShiftLeft
                || $node instanceof Node\Expr\BinaryOp\ShiftRight
            ) {
                return new IntegerType;
            }

            if ($node instanceof Node\Expr\Assign
                || $node instanceof Node\Expr\ErrorSuppress
                || $node instanceof Node\Expr\Clone_
            ) {
                return $this->resolveType($node->expr);
            }

            if ($node instanceof Node\Expr\AssignOp\Concat) {
                return new StringType;
            }

            if ($node instanceof Node\Expr\AssignOp\Plus
                || $node instanceof Node\Expr\AssignOp\Minus
                || $node instanceof Node\Expr\AssignOp\Mul
                || $node instanceof Node\Expr\AssignOp\Div
                || $node instanceof Node\Expr\AssignOp\Mod
                || $node instanceof Node\Expr\AssignOp\Pow
            ) {
                return new NumberType;
            }

            if ($node instanceof Node\Expr\AssignOp\BitwiseAnd
                || $node instanceof Node\Expr\AssignOp\BitwiseOr
                || $node instanceof Node\Expr\AssignOp\BitwiseXor
                || $node instanceof Node\Expr\AssignOp\ShiftLeft
                || $node instanceof Node\Expr\AssignOp\ShiftRight
            ) {
                return new IntegerType;
            }

            // `$x ??= y` keeps the existing non-null value of `$x`, else `y`.
            if ($node instanceof Node\Expr\AssignOp\Coalesce) {
                return new UnionType([
                    $this->resolveType($node->var)->removeNull($this->config),
                    $this->resolveType($node->expr),
                ]);
            }

            if ($node instanceof Node\Expr\BinaryOp\Pipe) {
                return new PhpPipeOperator($node, $this)->resolveType();
            }

            if ($node instanceof Node\Expr\ArrowFunction
                || $node instanceof Node\Expr\Closure
            ) {
                return new CallableType(
                    phpCallable: new PhpCallable(
                        scope: $this,
                        node: $node,
                    ),
                );
            }

            return new UnknownType;

        } finally {
            unset($this->nodesBeingResolved[$node]);
        }
    }


    private function resolveArrayDimFetchChain(Node\Expr\ArrayDimFetch $node): Type
    {
        $exactType = $this->resolveExactAccessChain($node);

        if ($exactType !== null) {
            return $exactType;
        }

        $rawKeys = [];
        $chainNode = $node;

        while ($chainNode instanceof Node\Expr\ArrayDimFetch && $chainNode->dim) {
            $rawKeys[] = $this->getRawValueFromNode($chainNode->dim);
            $chainNode = $chainNode->var;
        }

        $rawKeys = array_reverse($rawKeys);
        $readPath = [];

        foreach ($rawKeys as $rawKey) {
            if (is_int($rawKey) || is_string($rawKey)) {
                $readPath[] = $this->normalizeArrayKey($rawKey);

            } else {
                break;
            }
        }

        if ($chainNode instanceof Node\Expr\Variable) {
            $unresolvedVarType = $this->getVariableType($chainNode);

            $varType = $unresolvedVarType instanceof UnresolvedVariableType
                ? $unresolvedVarType->resolve($readPath)
                : $unresolvedVarType?->unwrapType($this->config) ?? new UnknownType;

        } else {
            $varType = $this->resolveType($chainNode);
        }

        foreach ($rawKeys as $rawKey) {
            $varType = $this->getTypeAtKey($varType, is_int($rawKey) ? $rawKey : (string) $rawKey);
        }

        return $varType;
    }


    private function resolveExactAccessChain(Node\Expr\ArrayDimFetch $node): ?Type
    {
        $keys = [];
        $chainNode = $node;

        while ($chainNode instanceof Node\Expr\ArrayDimFetch || $chainNode instanceof Node\Expr\PropertyFetch) {
            if ($chainNode instanceof Node\Expr\ArrayDimFetch) {
                if ($chainNode->dim === null) {
                    return null;
                }

                $rawKey = $this->getRawValueFromNode($chainNode->dim);

            } else {
                $rawKey = $this->getRawValueFromNode($chainNode->name);
            }

            if (! is_int($rawKey) && ! is_string($rawKey)) {
                return null;
            }

            $keys[] = $this->normalizeArrayKey($rawKey);
            $chainNode = $chainNode->var;
        }

        if (! $chainNode instanceof Node\Expr\Variable) {
            return null;
        }

        $unresolvedVarType = $this->getVariableType($chainNode);

        if (! $unresolvedVarType instanceof UnresolvedVariableType) {
            return null;
        }

        $keys = array_reverse($keys);

        $type = $unresolvedVarType->resolve($keys);

        foreach ($keys as $key) {
            $type = $this->getTypeAtKey($type, $key);
        }

        return $type instanceof UnknownType ? null : $type;
    }


    public function getTypeAtKey(Type $type, int|string $key): Type
    {
        if ($type instanceof ObjectType && $type->typeToDisplay) {
            $type = $type->typeToDisplay;
        }

        if ($type instanceof UnionType) {
            return new UnionType(
                array_map(fn (Type $memberType): Type => $this->getTypeAtKey($memberType, $key), $type->types),
            )->unwrapType($this->config);
        }

        if ($type instanceof ArrayType) {
            return ($type->shape[$key] ?? $type->itemType)?->unwrapType($this->config) ?? new UnknownType;
        }

        if ($type instanceof ObjectType) {
            return $this->getObjectPropertyType($type, $key) ?? new UnknownType;
        }

        return new UnknownType;
    }


    /**
     * Fall back to class metadata when `max_depth` leaves properties
     * unmaterialized, or nested mutations would be lost.
     */
    public function getObjectPropertyType(ObjectType $objectType, int|string $key): ?Type
    {
        $keyString = (string) $key;

        if (isset($objectType->properties[$keyString])) {
            return $objectType->properties[$keyString]->unwrapType($this->config);
        }

        if ($objectType->className !== null) {
            return $this->getPhpClass($objectType->className)->getProperty($keyString)?->unwrapType($this->config);
        }

        return null;
    }


    /**
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withoutScalarTypeValueMerging(callable $callback): mixed
    {
        $initialValues = [
            'show' => $this->config->data['openapi']['show_values_for_scalar_types'] ?? false,
            'merge' => $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? true,
        ];

        $this->config->data['openapi']['show_values_for_scalar_types'] = true;
        $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] = false;

        try {
            return $callback();

        } finally {
            $this->config->data['openapi']['show_values_for_scalar_types'] = $initialValues['show'];
            $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] = $initialValues['merge'];
        }
    }


    /**
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withScalarTypeValueMerging(callable $callback): mixed
    {
        $initialValues = [
            'show' => $this->config->data['openapi']['show_values_for_scalar_types'] ?? false,
            'merge' => $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] ?? true,
        ];

        $this->config->data['openapi']['show_values_for_scalar_types'] = false;
        $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] = true;

        try {
            return $callback();

        } finally {
            $this->config->data['openapi']['show_values_for_scalar_types'] = $initialValues['show'];
            $this->config->data['arrays']['remove_scalar_type_values_when_merging_with_unknown_types'] = $initialValues['merge'];
        }
    }


    /**
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withPartialArraysResolvingAsShapes(callable $callback): mixed
    {
        $initialValue = $this->config->data['arrays']['resolve_partial_shapes'] ?? false;

        $this->config->data['arrays']['resolve_partial_shapes'] = true;

        try {
            return $callback();

        } finally {
            $this->config->data['arrays']['resolve_partial_shapes'] = $initialValue;
        }
    }


    /**
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withDeepShapeInference(callable $callback): mixed
    {
        $initialValue = $this->config->data['arrays']['deep_shape_inference'] ?? false;

        $this->config->data['arrays']['deep_shape_inference'] = true;

        try {
            return $callback();

        } finally {
            $this->config->data['arrays']['deep_shape_inference'] = $initialValue;
        }
    }


    /**
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withShapeMerging(callable $callback): mixed
    {
        $initialArrayValue = $this->config->data['arrays']['merge_shapes_in_type_unions'] ?? false;
        $initialObjectValue = $this->config->data['objects']['merge_shapes_in_type_unions'] ?? false;

        $this->config->data['arrays']['merge_shapes_in_type_unions'] = true;
        $this->config->data['objects']['merge_shapes_in_type_unions'] = true;

        try {
            return $callback();

        } finally {
            $this->config->data['arrays']['merge_shapes_in_type_unions'] = $initialArrayValue;
            $this->config->data['objects']['merge_shapes_in_type_unions'] = $initialObjectValue;
        }
    }


    /**
     * Run $callback using validation semantics for scalar intersections, so
     * `string&number` narrows to numeric-string instead of `never`.
     *
     * @template TResult
     * @param (callable(): TResult) $callback
     * @return TResult
     */
    public function withCoerciveScalarOverlap(callable $callback): mixed
    {
        $initialValue = $this->config->data['intersections']['coercive_scalar_overlap'] ?? false;

        $this->config->data['intersections']['coercive_scalar_overlap'] = true;

        try {
            return $callback();

        } finally {
            $this->config->data['intersections']['coercive_scalar_overlap'] = $initialValue;
        }
    }


    /**
     * Run $callback with extension side effects disabled, so a call resolved only
     * to peek at its return type neither captures a request body nor mutates a
     * variable from incompletely-resolved arguments.
     *
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


    /**
     * Record a request body type from an extension side effect. Suppressed
     * during return-type peeks so an incompletely-resolved call can't leak.
     */
    public function recordRequestBodyType(Type $type): void
    {
        if ($this->suppressSideEffects) {
            return;
        }

        $this->route?->addRequestBodyType($type);
    }


    public function normalizeArrayKey(int|string $key): int|string
    {
        if (is_string($key) && (string) (int) $key === $key) {
            return (int) $key;
        }

        return $key;
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

        if ($name === 'parent') {
            return $this->getCurrentPhpClass()?->getParent()?->className;
        }

        if (str_starts_with($name, '\\')) {
            return PhpClass::removeLeadingBackslash($name);
        }

        if (! $this->className) {
            if (class_exists($name)) {
                return $name;
            }

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
        $scope = new Scope(
            config: $this->config,
            depth: $this->depth + 1,
            route: $this->route,
            className: $className,
            methodName: $methodName,
        );

        if ($this->className && $this->className === $className) {
            $scope->constructorArgs = $this->constructorArgs;
            $scope->constructorTemplateTypes = $this->constructorTemplateTypes;
        }

        return $scope;
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


    public function isDebugModeEnabled(): bool
    {
        return $this->config->data['debug']['enabled'] ?? false;
    }
}
