<?php declare(strict_types=1);

namespace AutoDoc\Extensions\BuiltIn;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Analyzer\IntKeyOrder;
use AutoDoc\Analyzer\Narrowing\AllOf;
use AutoDoc\Analyzer\Narrowing\AnyOf;
use AutoDoc\Analyzer\Narrowing\IsFalsey;
use AutoDoc\Analyzer\Narrowing\IsPresent;
use AutoDoc\Analyzer\Narrowing\IsTruthy;
use AutoDoc\Analyzer\Narrowing\IsType;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\Narrowing\NotType;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\Extensions\FuncCallContext;
use AutoDoc\Extensions\FuncCallExtension;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;


class ArrayFuncCall extends FuncCallExtension
{
    public function getReturnType(FuncCallContext $call): ?Type
    {
        if ($call->functionName === null) {
            return null;
        }

        $funcName = $call->functionName;
        $config = $call->scope->config;
        $argTypes = $call->argTypes;

        return match ($funcName) {
            'compact' => $this->handleCompact($call),
            'array_map' => $this->handleArrayMap($call->node, $call->scope, $argTypes),
            'array_values' => $this->handleArrayValues($config, $argTypes),
            'array_keys' => $this->handleArrayKeys($config, $argTypes),
            'array_flip' => $this->handleArrayFlip($config, $argTypes),
            'array_filter' => $this->handleArrayFilter($call->node, $call->scope, $argTypes),
            'array_unique' => $this->handleEntryRemovingFunction($argTypes, keepsFirstEntry: true),
            'array_diff', 'array_diff_assoc', 'array_diff_uassoc', 'array_diff_ukey',
            'array_udiff', 'array_udiff_assoc', 'array_udiff_uassoc',
            'array_intersect', 'array_intersect_assoc', 'array_intersect_uassoc', 'array_intersect_ukey',
            'array_uintersect', 'array_uintersect_assoc', 'array_uintersect_uassoc' => $this->handleEntryRemovingFunction($argTypes, keepsFirstEntry: false),
            'array_diff_key' => $this->handleKeyComparingFunction($config, $argTypes, keepsMatchingKeys: false),
            'array_intersect_key' => $this->handleKeyComparingFunction($config, $argTypes, keepsMatchingKeys: true),
            'array_slice' => $this->handleArraySlice($config, $argTypes),
            'array_reverse' => $this->handleArrayReverse($config, $argTypes),
            'array_merge' => $this->handleArrayMerge($config, $argTypes),
            'array_search' => $this->addFalse($this->getKeyType($config, $argTypes, 1), $config),
            'array_first', 'array_last', 'array_find', 'array_pop', 'array_shift' => $this->addNull($this->getItemType($config, $argTypes), $config),
            'array_key_first', 'array_key_last', 'array_find_key', 'key' => $this->addNull($this->getKeyType($config, $argTypes), $config),
            'end', 'current', 'next', 'prev', 'reset' => $this->addFalse($this->getItemType($config, $argTypes), $config),
            default => null,
        };
    }

    public function handleSideEffect(FuncCallContext $context): void
    {
        $preservesKeys = match ($context->functionName) {
            'sort', 'rsort', 'usort', 'shuffle' => false,
            'asort', 'arsort', 'uasort', 'uksort', 'krsort', 'natsort', 'natcasesort' => true,
            default => null,
        };

        $arrayArg = $context->node->args[0] ?? null;

        if ($preservesKeys === null
            || ! $arrayArg instanceof Node\Arg
            || ! $arrayArg->value instanceof Node\Expr\Variable
            || ! is_string($arrayArg->value->name)
        ) {
            return;
        }

        $config = $context->scope->config;
        $arrayType = $context->argTypes->get(0)->unwrapType($config);

        if (! $arrayType instanceof ArrayType) {
            return;
        }

        $sortedArrayType = $preservesKeys
            ? (clone $arrayType)->reorderItems($config)
            : (clone $arrayType)->toList($config);

        $context->setVarType($arrayArg->value->name, $sortedArrayType);
    }

    public function narrowTypeFromCondition(FuncCallContext $context, bool $negated): void
    {
        if ($context->functionName === 'in_array') {
            $this->handleInArrayNarrowing($context, $negated);

        } else if ($context->functionName === 'array_key_exists') {
            $this->handleArrayKeyExistsNarrowing($context, $negated);
        }
    }


    private function handleCompact(FuncCallContext $call): Type
    {
        $varNames = [];

        $getVarNames = function (Type $argType) use ($call, &$getVarNames, &$varNames): void {
            if ($argType instanceof ArrayType) {
                $getVarNames((clone $argType)->convertShapeToTypePair($call->scope->config)->itemType ?? new UnknownType);

            } else if ($argType instanceof StringType && is_string($argType->value)) {
                $varNames[] = $argType->value;
            }
        };

        $counter = count($call->argTypes);

        for ($i = 0; $i < $counter; $i++) {
            $getVarNames($call->argTypes->get($i));
        }

        $varTypes = [];

        foreach ($varNames as $varName) {
            $varTypes[$varName] = $call->scope->variables->getType(new Node\Expr\Variable($varName, [
                'startLine' => $call->node->getStartLine(),
                'endLine' => $call->node->getEndLine(),
                'startFilePos' => $call->node->getStartFilePos(),
                'endFilePos' => $call->node->getEndFilePos(),
            ])) ?? new UnknownType;
        }

        return new ArrayType(shape: $varTypes);
    }


    private function handleInArrayNarrowing(FuncCallContext $call, bool $negated): void
    {
        $needle = $call->node->args[0] ?? null;
        $haystack = $call->node->args[1] ?? null;

        if (! $needle instanceof Node\Arg || ! $haystack instanceof Node\Arg) {
            return;
        }

        $strict = false;
        $strictArg = $call->node->args[2] ?? null;

        if ($strictArg instanceof Node\Arg) {
            if (! $strictArg->value instanceof Node\Expr\ConstFetch) {
                return;
            }

            $strictValue = strtolower($strictArg->value->name->toString());

            if (! in_array($strictValue, ['true', 'false'], true)) {
                return;
            }

            $strict = $strictValue === 'true';
        }

        $literalTypes = $this->getLiteralArrayTypes($haystack->value, $call->scope);

        if ($literalTypes === []) {
            return;
        }

        $narrowing = $this->buildInArrayNarrowing($literalTypes, $strict, $negated);

        if ($needle->value instanceof Node\Expr\Variable && is_string($needle->value->name)) {
            $call->narrowVarType($needle->value->name, $narrowing);

            return;
        }

        $call->narrowExpressionType($needle->value, $narrowing);
    }


    private function handleArrayKeyExistsNarrowing(FuncCallContext $call, bool $negated): void
    {
        if ($negated) {
            return;
        }

        $key = $call->node->args[0] ?? null;
        $array = $call->node->args[1] ?? null;

        if (! $key instanceof Node\Arg || ! $array instanceof Node\Arg) {
            return;
        }

        $keyNode = $this->getLiteralArrayKeyNode($key->value, $call->scope);

        if ($keyNode === null) {
            return;
        }

        $call->narrowExpressionType(
            new Node\Expr\ArrayDimFetch($array->value, $keyNode),
            new IsPresent,
        );
    }


    private function getLiteralArrayKeyNode(Node $node, Scope $scope): ?Node\Expr
    {
        $key = $scope->getRawValueFromNode($node);

        return match (true) {
            is_int($key) => new Node\Scalar\Int_($key),
            is_string($key) => new Node\Scalar\String_($key),
            default => null,
        };
    }


    /**
     * Build the narrowing for `in_array($needle, [literals], $strict)`: the value
     * is one of the candidates, i.e. an OR over them (`AnyOf`). For `!in_array(...)`
     * it's the negation — none of them — i.e. an AND of the per-candidate
     * negations (`AllOf`). Each candidate narrows by strict identity or loose
     * comparison depending on `$strict`.
     *
     * @param non-empty-list<Type> $literalTypes
     */
    private function buildInArrayNarrowing(array $literalTypes, bool $strict, bool $negated): Narrowing
    {
        $narrowings = array_map(
            fn (Type $type): Narrowing => $negated ? new NotType($type, $strict) : new IsType($type, $strict),
            $literalTypes,
        );

        if (count($narrowings) === 1) {
            return $narrowings[0];
        }

        return $negated ? new AllOf($narrowings) : new AnyOf($narrowings);
    }


    /**
     * @return list<Type>
     */
    private function getLiteralArrayTypes(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\Array_) {
            return [];
        }

        $types = [];

        foreach ($node->items as $item) {
            if (! $this->isLiteralScalarNode($item->value)) {
                return [];
            }

            $types[] = $scope->resolveType($item->value);
        }

        return $types;
    }


    private function isLiteralScalarNode(Node $node): bool
    {
        return $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\Int_
            || $node instanceof Node\Scalar\Float_
            || ($node instanceof Node\Expr\ConstFetch && in_array($node->name->toString(), ['true', 'false'], true));
    }


    private function handleArrayMap(FuncCall $funcCall, Scope $scope, ArgumentList $argTypes): Type
    {
        $callbackType = $argTypes->get(0);
        $config = $scope->config;

        if ($callbackType instanceof CallableType) {
            $argCount = count($argTypes);

            if ($argCount === 2) {
                $arrayType = $this->getArrayArgument($argTypes, 1);

                if ($arrayType !== null) {
                    $itemType = $arrayType->convertShapeToTypePair($config)->itemType ?? new UnknownType;
                    $keyType = $arrayType->keyType;

                } else {
                    // Even an unresolvable array is worth mapping: the callback
                    // return shape (and its param type hints) still applies.
                    $itemType = new UnknownType;
                    $keyType = null;
                }

                return new ArrayType(
                    itemType: $callbackType->getReturnType(
                        args: ArgumentList::fromTypes([$itemType], $scope),
                        callerNode: $funcCall,
                    ),
                    keyType: $keyType,
                    intKeyOrder: $arrayType->intKeyOrder ?? IntKeyOrder::Sequential,
                );
            }

            $closureArgTypes = [];

            for ($i = 1; $i < $argCount; $i++) {
                $argType = $argTypes->get($i)->unwrapType($config);

                if ($argType instanceof ArrayType) {
                    $closureArgTypes[] = (clone $argType)->convertShapeToTypePair($config)->itemType ?? new UnknownType;

                } else {
                    $closureArgTypes[] = new UnknownType;
                }
            }

            return new ArrayType(
                itemType: $callbackType->getReturnType(ArgumentList::fromTypes($closureArgTypes, $scope), $funcCall),
            );

        } else if ($callbackType instanceof NullType) {
            $argCount = count($argTypes);

            if ($argCount === 2) {
                return $argTypes->get(1);
            }

            $itemTypes = [];

            for ($i = 1; $i < $argCount; $i++) {
                $type = $argTypes->get($i)->unwrapType($config);

                if ($type instanceof ArrayType) {
                    $itemTypes[] = (clone $type)->convertShapeToTypePair($config)->itemType ?? new UnknownType;

                } else {
                    return new ArrayType;
                }
            }

            return new ArrayType(
                itemType: new ArrayType(
                    itemType: new UnionType($itemTypes)->unwrapType($config),
                ),
            );
        }

        return new ArrayType;
    }


    private function handleArrayValues(Config $config, ArgumentList $argTypes): Type
    {
        return $this->getArrayArgument($argTypes)?->toList($config) ?? new ArrayType;
    }


    private function handleArrayKeys(Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType !== null) {
            return new ArrayType(
                itemType: $arrayType->convertShapeToTypePair($config)->keyType,
            );
        }

        return new ArrayType(itemType: new UnionType([
            new IntegerType,
            new StringType,
        ]));
    }


    private function handleArrayFlip(Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType !== null) {
            return $arrayType->flipKeysAndItems($config);
        }

        return new ArrayType(
            itemType: new UnionType([
                new IntegerType,
                new StringType,
            ]),
            keyType: new UnionType([
                new IntegerType,
                new StringType,
            ]),
        );
    }


    private function handleArrayFilter(FuncCall $funcCall, Scope $scope, ArgumentList $argTypes): Type
    {
        $config = $scope->config;
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType === null) {
            return new ArrayType;
        }

        if (! isset($funcCall->args[1])) {
            $truthy = new IsTruthy;
            $falsey = new IsFalsey;

            if ($arrayType->shape) {
                foreach ($arrayType->shape as $key => $value) {
                    $truthyValue = $truthy->apply($value, $scope);

                    if ($truthyValue instanceof NeverType) {
                        $arrayType->removeItemFromArray($key);

                        continue;
                    }

                    $arrayType->shape[$key] = $truthyValue;

                    if (! ($falsey->apply($value, $scope) instanceof NeverType)) {
                        $arrayType->removeItemFromArray($key, isCertain: false);
                    }
                }

            } else if ($arrayType->itemType !== null) {
                if (! ($falsey->apply($arrayType->itemType, $scope) instanceof NeverType)) {
                    $arrayType->markItemsOptional();
                }

                $arrayType->itemType = $truthy->apply($arrayType->itemType, $scope);
            }

        } else {
            $callbackType = $argTypes->get(1);
            $mode = $this->getArrayFilterMode($funcCall, $scope);

            if ($arrayType->shape) {
                foreach (array_keys($arrayType->shape) as $key) {
                    $value = $arrayType->shape[$key];
                    $filteredValue = $this->filterShapeValueByCallback(
                        callbackType: $callbackType,
                        value: $value,
                        key: $key,
                        mode: $mode,
                        funcCall: $funcCall,
                        scope: $scope,
                    );

                    if ($filteredValue instanceof NeverType) {
                        $arrayType->removeItemFromArray($key);

                        continue;
                    }

                    $arrayType->shape[$key] = $filteredValue;
                    $arrayType->removeItemFromArray($key, isCertain: false);
                }

            } else {
                $arrayType->markItemsOptional();

                $narrowedItemType = (($mode === 'value' || $mode === 'both') && $arrayType->itemType !== null)
                    ? $this->narrowFilteredArgumentType(
                        callbackType: $callbackType,
                        argumentIndex: 0,
                        argumentType: $arrayType->itemType,
                        funcCall: $funcCall,
                        scope: $scope,
                        allowStringTypeCheckFunction: $mode === 'value',
                    )
                    : null;

                if ($narrowedItemType !== null) {
                    $arrayType->itemType = $narrowedItemType;

                } else if ($mode === 'value' || $mode === null) {
                    $arrayType->convertShapeToTypePair($config, removePossibleItemValues: true);
                }
            }
        }

        return $arrayType;
    }


    /**
     * @param 'value'|'key'|'both'|null $mode
     */
    private function filterShapeValueByCallback(
        Type $callbackType,
        Type $value,
        int|string $key,
        ?string $mode,
        FuncCall $funcCall,
        Scope $scope,
    ): Type {

        if ($mode === 'key' || $mode === 'both') {
            $keyArgumentIndex = $mode === 'key' ? 0 : 1;
            $narrowedKeyType = $this->narrowFilteredArgumentType(
                callbackType: $callbackType,
                argumentIndex: $keyArgumentIndex,
                argumentType: $this->arrayFilterKeyType($key),
                funcCall: $funcCall,
                scope: $scope,
                allowStringTypeCheckFunction: $mode === 'key',
            );

            if ($narrowedKeyType instanceof NeverType) {
                return new NeverType;
            }
        }

        if ($mode === 'value' || $mode === 'both') {
            $narrowedValueType = $this->narrowFilteredArgumentType(
                callbackType: $callbackType,
                argumentIndex: 0,
                argumentType: $value,
                funcCall: $funcCall,
                scope: $scope,
                allowStringTypeCheckFunction: $mode === 'value',
            );

            if ($narrowedValueType !== null) {
                return $narrowedValueType;
            }
        }

        return $value;
    }


    /**
     * Resolve the type an `array_filter` callback narrows one argument to.
     * Supports inline callables (`fn ($x) => $x instanceof Foo`) and string
     * type-check function names (`'is_string'`) when PHP passes only one
     * argument to that string callback.
     */
    private function narrowFilteredArgumentType(
        Type $callbackType,
        int $argumentIndex,
        Type $argumentType,
        FuncCall $funcCall,
        Scope $scope,
        bool $allowStringTypeCheckFunction,
    ): ?Type {
        if ($callbackType instanceof CallableType) {
            return $callbackType->narrowArgumentTypeFromTruthyReturn($argumentIndex, $argumentType, $funcCall);
        }

        if ($allowStringTypeCheckFunction && $callbackType instanceof StringType && is_string($callbackType->value)) {
            return TypeCheckFuncCall::narrowingForFunction($callbackType->value)?->apply($argumentType, $scope);
        }

        return null;
    }


    private function arrayFilterKeyType(int|string $key): Type
    {
        return is_int($key) ? new IntegerType($key) : new StringType($key);
    }


    /**
     * @return 'value'|'key'|'both'|null
     */
    private function getArrayFilterMode(FuncCall $funcCall, Scope $scope): ?string
    {
        $modeArg = $funcCall->args[2] ?? null;

        if (! $modeArg instanceof Node\Arg) {
            return 'value';
        }

        $mode = $this->getArrayFilterModeValue($modeArg->value, $scope);

        return match ($mode) {
            0 => 'value',
            \ARRAY_FILTER_USE_KEY => 'key',
            \ARRAY_FILTER_USE_BOTH => 'both',
            default => null,
        };
    }


    private function getArrayFilterModeValue(Node $node, Scope $scope): ?int
    {
        if ($node instanceof Node\Expr\ConstFetch) {
            $constantName = strtolower(ltrim($node->name->toString(), '\\'));

            return match ($constantName) {
                'array_filter_use_key' => \ARRAY_FILTER_USE_KEY,
                'array_filter_use_both' => \ARRAY_FILTER_USE_BOTH,
                default => null,
            };
        }

        $value = $scope->getRawValueFromNode($node);

        return is_int($value) ? $value : null;
    }


    private function handleEntryRemovingFunction(ArgumentList $argTypes, bool $keepsFirstEntry): Type
    {
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType === null) {
            return new ArrayType;
        }

        $minItems = $keepsFirstEntry && $arrayType->minItems !== null ? min($arrayType->minItems, 1) : null;

        if ($arrayType->shape === []) {
            $arrayType->markItemsOptional();

        } else {
            $keptKey = $keepsFirstEntry ? array_key_first($arrayType->shape) : null;

            foreach (array_keys($arrayType->shape) as $key) {
                if ($key !== $keptKey) {
                    $arrayType->removeItemFromArray($key, isCertain: false);
                }
            }
        }

        $arrayType->minItems = $minItems;

        return $arrayType;
    }


    private function handleKeyComparingFunction(Config $config, ArgumentList $argTypes, bool $keepsMatchingKeys): Type
    {
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType === null) {
            return new ArrayType;
        }

        if ($arrayType->shape === []) {
            return $arrayType->markItemsOptional();
        }

        $comparedArrays = [];
        $counter = count($argTypes);

        for ($i = 1; $i < $counter; $i++) {
            $comparedArray = $argTypes->get($i)->unwrapType($config);
            $comparedArrays[] = $comparedArray instanceof ArrayType ? $comparedArray : new ArrayType;
        }

        foreach (array_keys($arrayType->shape) as $key) {
            $presences = array_map(fn (ArrayType $comparedArray): ?bool => $this->getKeyPresence($comparedArray, $key), $comparedArrays);

            $isRemoved = in_array(! $keepsMatchingKeys, $presences, true);
            $isKept = array_all($presences, fn (?bool $presence) => $presence === $keepsMatchingKeys);

            if ($isRemoved || ! $isKept) {
                $arrayType->removeItemFromArray($key, isCertain: $isRemoved);
            }
        }

        return $arrayType;
    }


    /**
     * True when the key is always present, false when it never is, null when it may be.
     */
    private function getKeyPresence(ArrayType $arrayType, int|string $key): ?bool
    {
        if (isset($arrayType->shape[$key])) {
            return $arrayType->shape[$key]->required ? true : null;
        }

        $mayHoldKeyOutsideShape = $arrayType->shape === [] || $arrayType->hasEntriesOutsideShape();

        return $mayHoldKeyOutsideShape ? null : false;
    }


    private function handleArraySlice(Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType === null) {
            return new ArrayType;
        }

        $typePair = $arrayType->convertShapeToTypePair($config)->markItemsOptional();

        return $this->getBoolArgument($argTypes, 'preserve_keys', 3, $config, default: false) === false
            ? $typePair->renumberKeys($config)
            : $typePair;
    }


    private function handleArrayReverse(Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $this->getArrayArgument($argTypes);

        if ($arrayType === null) {
            return new ArrayType;
        }

        $preservesKeys = $this->getBoolArgument($argTypes, 'preserve_keys', 1, $config, default: false);

        if ($preservesKeys !== null && $this->knownEntriesStayAtKnownKeysWhenReversed($arrayType)) {
            $arrayType->shape = array_reverse($arrayType->shape, preserve_keys: true);

            return $preservesKeys ? $arrayType : $arrayType->renumberKeys($config);
        }

        $arrayType->convertShapeToTypePair($config);

        return $preservesKeys === false ? $arrayType->renumberKeys($config) : $arrayType->reorderItems($config);
    }


    private function knownEntriesStayAtKnownKeysWhenReversed(ArrayType $arrayType): bool
    {
        $everyKnownEntryExists = $arrayType->shape !== [] && array_all($arrayType->shape, fn (Type $entry) => $entry->required);
        $hasKnownIntKey = array_any(array_keys($arrayType->shape), fn (int|string $key) => is_int($key));

        return $everyKnownEntryExists && ! ($hasKnownIntKey && $arrayType->hasEntriesOutsideShape());
    }


    private function getBoolArgument(ArgumentList $argTypes, string $name, int $position, Config $config, bool $default): ?bool
    {
        $index = $argTypes->indexForParameter($name, $position);

        if ($index === null) {
            return $default;
        }

        $argType = $argTypes->get($index)->unwrapType($config);

        return $argType instanceof BoolType ? $argType->value : null;
    }


    private function handleArrayMerge(Config $config, ArgumentList $argTypes): Type
    {
        $mergedArray = new ArrayType;
        $itemTypes = [];
        $keyTypes = [];
        $hasShape = true;
        $counter = count($argTypes);

        for ($i = 0; $i < $counter; $i++) {
            $argType = $this->getArrayArgument($argTypes, $i);

            if ($argType === null) {
                return new ArrayType;
            }

            if ($argType->shape && $hasShape) {
                $mergedArray->mergeShape($argType->shape, isTuple: $argType->isTuple());

            } else {
                $hasShape = false;
            }

            $typePair = $argType->convertShapeToTypePair($config);

            $itemTypes[] = $typePair->itemType ?? new UnknownType;
            $keyTypes[] = $typePair->getRenumberedKeyType($config) ?? new IntegerType;
        }

        if ($hasShape) {
            return $mergedArray;
        }

        return new ArrayType(
            itemType: new UnionType($itemTypes)->unwrapType($config),
            keyType: new UnionType($keyTypes)->unwrapType($config),
        );
    }


    /**
     * Argument types are shared with the scope that resolved them, so a handler changes only this copy.
     */
    private function getArrayArgument(ArgumentList $argTypes, int $index = 0): ?ArrayType
    {
        $argType = $argTypes->has($index) ? $argTypes->get($index) : null;

        return $argType instanceof ArrayType ? clone $argType : null;
    }


    private function getItemType(Config $config, ArgumentList $argTypes): Type
    {
        return $this->getArrayArgument($argTypes)?->convertShapeToTypePair($config)->itemType ?? new UnknownType;
    }


    private function getKeyType(Config $config, ArgumentList $argTypes, int $arrayArgPosition = 0): Type
    {
        $arrayType = $this->getArrayArgument($argTypes, $arrayArgPosition);

        if ($arrayType !== null) {
            return $arrayType->convertShapeToTypePair($config)->keyType ?? new IntegerType;
        }

        return new UnionType([
            new IntegerType,
            new StringType,
        ]);
    }


    private function addNull(Type $type, Config $config): Type
    {
        return new UnionType([$type, new NullType])->unwrapType($config);
    }


    private function addFalse(Type $type, Config $config): Type
    {
        return new UnionType([$type, new BoolType(false)])->unwrapType($config);
    }
}
