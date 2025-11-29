<?php declare(strict_types=1);

namespace AutoDoc\Extensions\BuiltIn;

use AutoDoc\Analyzer\PhpFunctionArgument;
use AutoDoc\Analyzer\Scope;
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
    public function getReturnType(FuncCall $funcCall, Scope $scope): ?Type
    {
        if (! $funcCall->name instanceof Node\Name) {
            return null;
        }

        $funcName = $funcCall->name->name;

        return match ($funcName) {
            'array_map' => $this->handleArrayMap($funcCall, $scope),
            'array_values' => $this->handleArrayValues($funcCall, $scope),
            'array_keys' => $this->handleArrayKeys($funcCall, $scope),
            'array_flip' => $this->handleArrayFlip($funcCall, $scope),
            'array_filter' => $this->handleArrayFilter($funcCall, $scope),
            'array_merge' => $this->handleArrayMerge($funcCall, $scope),
            'compact' => $this->handleCompact($funcCall, $scope),
            'array_search' => $this->addFalse($this->getKeyType($funcCall, $scope, 1), $scope),
            'array_first', 'array_last', 'array_find', 'array_pop', 'array_shift' => $this->addNull($this->getItemType($funcCall, $scope), $scope),
            'array_key_first', 'array_key_last', 'array_find_key', 'key' => $this->addNull($this->getKeyType($funcCall, $scope), $scope),
            'end', 'current', 'next', 'prev', 'reset' => $this->addFalse($this->getItemType($funcCall, $scope), $scope),
            default => null,
        };
    }


    private function handleArrayMap(FuncCall $funcCall, Scope $scope): Type
    {
        $callbackArg = $funcCall->args[0] ?? null;

        if (! ($callbackArg instanceof Node\Arg)) {
            return new ArrayType;
        }

        $callbackType = $scope->resolveType($callbackArg->value);

        if ($callbackType instanceof CallableType) {
            $argCount = count($funcCall->args);

            if ($argCount === 2) {
                $arrayType = $funcCall->args[1] instanceof Node\Arg ? $scope->resolveType($funcCall->args[1]->value) : null;

                if ($arrayType instanceof ArrayType) {
                    return new ArrayType(
                        itemType: $callbackType->getReturnType([
                            new PhpFunctionArgument($arrayType->convertShapeToTypePair($scope->config)->itemType ?? new UnknownType, $scope),
                        ]),
                        keyType: $arrayType->keyType,
                    );
                }

                return new ArrayType;
            }

            $closureArgs = [];

            for ($i = 1; $i < $argCount; $i++) {
                $argType = $funcCall->args[$i] instanceof Node\Arg
                    ? $scope->resolveType($funcCall->args[$i]->value)->unwrapType($scope->config)
                    : new UnknownType;

                if ($argType instanceof ArrayType) {
                    $closureArgs[] = new PhpFunctionArgument($argType->convertShapeToTypePair($scope->config)->itemType ?? new UnknownType, $scope);

                } else {
                    $closureArgs[] = new PhpFunctionArgument(new UnknownType, $scope);
                }
            }

            return new ArrayType(
                itemType: $callbackType->getReturnType($closureArgs),
            );

        } else if ($callbackType instanceof NullType) {
            $argCount = count($funcCall->args);

            if ($argCount === 2) {
                return $funcCall->args[1] instanceof Node\Arg ? $scope->resolveType($funcCall->args[1]->value) : new UnknownType;
            }

            $itemTypes = [];

            for ($i = 1; $i < $argCount; $i++) {
                $type = $funcCall->args[$i] instanceof Node\Arg
                    ? $scope->resolveType($funcCall->args[$i]->value)->unwrapType($scope->config)
                    : new UnknownType;

                if ($type instanceof ArrayType) {
                    $itemTypes[] = $type->convertShapeToTypePair($scope->config)->itemType ?? new UnknownType;

                } else {
                    return new ArrayType;
                }
            }

            return new ArrayType(
                itemType: new ArrayType(
                    itemType: (new UnionType($itemTypes))->unwrapType($scope->config),
                ),
            );
        }

        return new ArrayType;
    }


    private function handleArrayValues(FuncCall $funcCall, Scope $scope): Type
    {
        $arrayArg = $funcCall->args[0] ?? null;

        if (! ($arrayArg instanceof Node\Arg)) {
            return new ArrayType;
        }

        $arrayType = $scope->resolveType($arrayArg->value);

        if ($arrayType instanceof ArrayType) {
            $arrayType->convertShapeToTypePair($scope->config);
            $arrayType->keyType = null;

            return $arrayType;
        }

        return new ArrayType;
    }


    private function handleArrayKeys(FuncCall $funcCall, Scope $scope): Type
    {
        $arrayArg = $funcCall->args[0] ?? null;

        if (! ($arrayArg instanceof Node\Arg)) {
            return new ArrayType(itemType: new UnionType([
                new IntegerType,
                new StringType,
            ]));
        }

        $arrayType = $scope->resolveType($arrayArg->value);

        if ($arrayType instanceof ArrayType) {
            return new ArrayType(
                itemType: $arrayType->convertShapeToTypePair($scope->config)->keyType,
            );
        }

        return new ArrayType(itemType: new UnionType([
            new IntegerType,
            new StringType,
        ]));
    }


    private function handleArrayFlip(FuncCall $funcCall, Scope $scope): Type
    {
        $arrayArg = $funcCall->args[0] ?? null;

        if ($arrayArg instanceof Node\Arg) {
            $arrayType = $scope->resolveType($arrayArg->value);

            if ($arrayType instanceof ArrayType) {
                $arrayType = $arrayType->convertShapeToTypePair($scope->config);

                $keyType = $arrayType->itemType;

                $arrayType->itemType = $arrayType->keyType;
                $arrayType->keyType = $keyType;

                return $arrayType;
            }
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


    private function handleArrayFilter(FuncCall $funcCall, Scope $scope): Type
    {
        $arrayArg = $funcCall->args[0] ?? null;

        if (! ($arrayArg instanceof Node\Arg)) {
            return new ArrayType;
        }

        $arrayType = $scope->resolveType($arrayArg->value);

        if ($arrayType instanceof ArrayType) {
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

                            $value = (new UnionType($typesInUnion))->unwrapType($scope->config);
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

                    $arrayType->itemType = (new UnionType($typesInUnion))->unwrapType($scope->config);
                }

            } else {
                if ($arrayType->shape) {
                    foreach ($arrayType->shape as $key => $value) {
                        $arrayType->shape[$key]->setRequired(false);
                    }

                } else {
                    $arrayType->convertShapeToTypePair($scope->config, removePossibleItemValues: true);
                }
            }

            return $arrayType;
        }

        return new ArrayType;
    }


    private function handleArrayMerge(FuncCall $funcCall, Scope $scope): Type
    {
        $shape = [];
        $itemTypes = [];
        $keyTypes = [];
        $hasShape = true;

        foreach ($funcCall->args as $arg) {
            if (! ($arg instanceof Node\Arg)) {
                return new ArrayType;
            }

            $argType = $scope->resolveType($arg->value);

            if (! ($argType instanceof ArrayType)) {
                return new ArrayType;
            }

            if ($argType->shape && $hasShape) {
                $shape = array_merge($shape, $argType->shape);

            } else {
                $hasShape = false;
            }

            $typePair = $argType->convertShapeToTypePair($scope->config);

            $itemTypes[] = $typePair->itemType ?? new UnknownType;
            $keyTypes[] = $typePair->keyType ?? new UnknownType;
        }

        if ($hasShape) {
            return new ArrayType(
                shape: $shape,
            );
        }

        return new ArrayType(
            itemType: (new UnionType($itemTypes))->unwrapType($scope->config),
            keyType: (new UnionType($keyTypes))->unwrapType($scope->config),
        );
    }


    private function handleCompact(FuncCall $funcCall, Scope $scope): Type
    {
        $varNames = [];

        $getVarNames = function (Type $argType) use ($scope, &$getVarNames, &$varNames): void {
            if ($argType instanceof ArrayType) {
                $getVarNames($argType->convertShapeToTypePair($scope->config)->itemType ?? new UnknownType);

            } else if ($argType instanceof StringType) {
                if (is_string($argType->value)) {
                    $varNames[] = $argType->value;
                }
            }
        };

        foreach ($funcCall->args as $arg) {
            if (! ($arg instanceof Node\Arg)) {
                return new ArrayType(
                    keyType: new StringType,
                    itemType: new UnknownType,
                );
            }

            $argType = $scope->resolveType($arg->value);

            $getVarNames($argType);
        }

        $varTypes = [];

        foreach ($varNames as $varName) {
            $varTypes[$varName] = $scope->getVariableType(new Node\Expr\Variable($varName, [
                'startLine' => $funcCall->getStartLine(),
                'endLine' => $funcCall->getEndLine(),
                'startFilePos' => $funcCall->getStartFilePos(),
                'endFilePos' => $funcCall->getEndFilePos(),
            ])) ?? new UnknownType;
        }

        return new ArrayType(
            shape: $varTypes,
        );
    }


    private function getItemType(FuncCall $funcCall, Scope $scope): Type
    {
        $arrayArg = $funcCall->args[0] ?? null;

        if (! ($arrayArg instanceof Node\Arg)) {
            return new UnknownType;
        }

        $arrayType = $scope->resolveType($arrayArg->value);

        if ($arrayType instanceof ArrayType) {
            return $arrayType->convertShapeToTypePair($scope->config)->itemType ?? new UnknownType;
        }

        return new UnknownType;
    }


    private function getKeyType(FuncCall $funcCall, Scope $scope, int $arrayArgPosition = 0): Type
    {
        $arrayArg = $funcCall->args[$arrayArgPosition] ?? null;

        if (! ($arrayArg instanceof Node\Arg)) {
            return new UnionType([
                new IntegerType,
                new StringType,
            ]);
        }

        $arrayType = $scope->resolveType($arrayArg->value);

        if ($arrayType instanceof ArrayType) {
            return $arrayType->convertShapeToTypePair($scope->config)->keyType ?? new IntegerType;
        }

        return new UnionType([
            new IntegerType,
            new StringType,
        ]);
    }


    private function addNull(Type $type, Scope $scope): Type
    {
        return (new UnionType([$type, new NullType]))->unwrapType($scope->config);
    }


    private function addFalse(Type $type, Scope $scope): Type
    {
        return (new UnionType([$type, new BoolType(false)]))->unwrapType($scope->config);
    }
}
