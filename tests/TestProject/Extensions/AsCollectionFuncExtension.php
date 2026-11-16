<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use ArrayObject;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\FuncCallContext;
use AutoDoc\Extensions\FuncCallExtension;

/**
 * Resolves `as_collection($array)` as its array type with a `className`, the way
 * collection extensions resolve objects that encode as arrays.
 */
class AsCollectionFuncExtension extends FuncCallExtension
{
    public function getReturnType(FuncCallContext $context): ?Type
    {
        if ($context->functionName !== 'as_collection') {
            return null;
        }

        $arrayType = $context->argTypes->get(0)->unwrapType($context->scope->config);

        if (! $arrayType instanceof ArrayType) {
            return null;
        }

        $collectionType = clone $arrayType;
        $collectionType->className = ArrayObject::class;

        return $collectionType;
    }
}
