<?php declare(strict_types=1);

namespace AutoDoc\Extensions\BuiltIn;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\Analyzer\Narrowing\AllOf;
use AutoDoc\Analyzer\Narrowing\AnyOf;
use AutoDoc\Analyzer\Narrowing\IsType;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\Narrowing\NotType;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
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
            'array_filter' => $this->handleArrayFilter($call->node, $config, $argTypes),
            'array_merge' => $this->handleArrayMerge($config, $argTypes),
            'array_search' => $this->addFalse($this->getKeyType($config, $argTypes, 1), $config),
            'array_first', 'array_last', 'array_find', 'array_pop', 'array_shift' => $this->addNull($this->getItemType($config, $argTypes), $config),
            'array_key_first', 'array_key_last', 'array_find_key', 'key' => $this->addNull($this->getKeyType($config, $argTypes), $config),
            'end', 'current', 'next', 'prev', 'reset' => $this->addFalse($this->getItemType($config, $argTypes), $config),
            default => null,
        };
    }

    public function narrowTypeFromCondition(FuncCallContext $context, bool $negated): void
    {
        if ($context->functionName !== 'in_array') {
            return;
        }

        $this->handleInArrayNarrowing($context, $negated);
    }


    private function handleCompact(FuncCallContext $call): Type
    {
        $varNames = [];

        $getVarNames = function (Type $argType) use ($call, &$getVarNames, &$varNames): void {
            if ($argType instanceof ArrayType) {
                $getVarNames($argType->convertShapeToTypePair($call->scope->config)->itemType ?? new UnknownType);

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
            $varTypes[$varName] = $call->scope->getVariableType(new Node\Expr\Variable($varName, [
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
                $arrayType = $argTypes->get(1);

                if ($arrayType instanceof ArrayType) {
                    return new ArrayType(
                        itemType: $callbackType->getReturnType(
                            args: ArgumentList::fromTypes([
                                $arrayType->convertShapeToTypePair($config)->itemType ?? new UnknownType,
                            ], $scope),
                            callerNode: $funcCall,
                        ),
                        keyType: $arrayType->keyType,
                    );
                }

                return new ArrayType;
            }

            $closureArgTypes = [];

            for ($i = 1; $i < $argCount; $i++) {
                $argType = $argTypes->get($i)->unwrapType($config);

                if ($argType instanceof ArrayType) {
                    $closureArgTypes[] = $argType->convertShapeToTypePair($config)->itemType ?? new UnknownType;

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
                    $itemTypes[] = $type->convertShapeToTypePair($config)->itemType ?? new UnknownType;

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
        $arrayType = $argTypes->has(0) ? $argTypes->get(0) : null;

        if ($arrayType instanceof ArrayType) {
            $arrayType->convertShapeToTypePair($config);
            $arrayType->keyType = null;

            return $arrayType;
        }

        return new ArrayType;
    }


    private function handleArrayKeys(Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $argTypes->has(0) ? $argTypes->get(0) : null;

        if ($arrayType instanceof ArrayType) {
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
        $arrayType = $argTypes->has(0) ? $argTypes->get(0) : null;

        if ($arrayType instanceof ArrayType) {
            $arrayType = $arrayType->convertShapeToTypePair($config);

            $keyType = $arrayType->itemType;

            $arrayType->itemType = $arrayType->keyType;
            $arrayType->keyType = $keyType;

            return $arrayType;
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


    private function handleArrayFilter(FuncCall $funcCall, Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $argTypes->has(0) ? $argTypes->get(0) : null;

        if (! ($arrayType instanceof ArrayType)) {
            return new ArrayType;
        }

        if (! isset($funcCall->args[1])) {
            if ($arrayType->shape) {
                $shape = [];

                foreach ($arrayType->shape as $key => $value) {
                    if ($value instanceof UnionType) {
                        $typesInUnion = [];

                        foreach ($value->types as $typeInUnion) {
                            if ($typeInUnion instanceof NullType) {
                                continue;
                            }

                            $typesInUnion[] = $typeInUnion;
                        }

                        $value = new UnionType($typesInUnion)->unwrapType($config);
                    }

                    $shape[$key] = $value;
                }

                $arrayType->shape = $shape;

            } else if ($arrayType->itemType instanceof UnionType) {
                $typesInUnion = [];

                foreach ($arrayType->itemType->types as $typeInUnion) {
                    if ($typeInUnion instanceof NullType) {
                        continue;
                    }

                    $typesInUnion[] = $typeInUnion;
                }

                $arrayType->itemType = new UnionType($typesInUnion)->unwrapType($config);
            }

        } else {
            if ($arrayType->shape) {
                foreach (array_keys($arrayType->shape) as $key) {
                    $arrayType->shape[$key]->setRequired(false);
                }

            } else {
                $arrayType->convertShapeToTypePair($config, removePossibleItemValues: true);
            }
        }

        return $arrayType;
    }


    private function handleArrayMerge(Config $config, ArgumentList $argTypes): Type
    {
        $shape = [];
        $itemTypes = [];
        $keyTypes = [];
        $hasShape = true;
        $counter = count($argTypes);

        for ($i = 0; $i < $counter; $i++) {
            $argType = $argTypes->get($i);

            if (! ($argType instanceof ArrayType)) {
                return new ArrayType;
            }

            if ($argType->shape && $hasShape) {
                $shape = array_merge($shape, $argType->shape);

            } else {
                $hasShape = false;
            }

            $typePair = $argType->convertShapeToTypePair($config);

            $itemTypes[] = $typePair->itemType ?? new UnknownType;
            $keyTypes[] = $typePair->keyType ?? new UnknownType;
        }

        if ($hasShape) {
            return new ArrayType(
                shape: $shape,
            );
        }

        return new ArrayType(
            itemType: new UnionType($itemTypes)->unwrapType($config),
            keyType: new UnionType($keyTypes)->unwrapType($config),
        );
    }


    private function getItemType(Config $config, ArgumentList $argTypes): Type
    {
        $arrayType = $argTypes->has(0) ? $argTypes->get(0) : null;

        if ($arrayType instanceof ArrayType) {
            return $arrayType->convertShapeToTypePair($config)->itemType ?? new UnknownType;
        }

        return new UnknownType;
    }


    private function getKeyType(Config $config, ArgumentList $argTypes, int $arrayArgPosition = 0): Type
    {
        $arrayType = $argTypes->has($arrayArgPosition) ? $argTypes->get($arrayArgPosition) : null;

        if ($arrayType instanceof ArrayType) {
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
