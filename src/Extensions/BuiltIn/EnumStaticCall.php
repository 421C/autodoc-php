<?php declare(strict_types=1);

namespace AutoDoc\Extensions\BuiltIn;

use AutoDoc\Analyzer\StaticCallContext;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Extensions\StaticCallExtension;
use BackedEnum;


class EnumStaticCall extends StaticCallExtension
{
    public function getReturnType(StaticCallContext $context): ?Type
    {
        if ($context->className === null
            || ! in_array($context->methodName, ['cases', 'from', 'tryFrom'], true)
        ) {
            return null;
        }

        $phpClass = $context->scope->getPhpClassInDeeperScope($context->className);

        if (! $phpClass->exists() || ! $phpClass->getReflection()->isEnum()) {
            return null;
        }

        if ($context->methodName === 'cases') {
            return new ArrayType(itemType: $phpClass->resolveType());
        }

        if (! $phpClass->getReflection()->implementsInterface(BackedEnum::class)) {
            return null;
        }

        if ($context->methodName === 'tryFrom') {
            return new UnionType([$phpClass->resolveType(), new NullType]);
        }

        return $phpClass->resolveType();
    }
}
