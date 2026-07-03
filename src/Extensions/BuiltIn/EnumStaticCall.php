<?php declare(strict_types=1);

namespace AutoDoc\Extensions\BuiltIn;

use AutoDoc\Analyzer\StaticCallContext;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\StaticCallExtension;


class EnumStaticCall extends StaticCallExtension
{
    public function getReturnType(StaticCallContext $context): ?Type
    {
        if ($context->methodName !== 'cases' || $context->className === null) {
            return null;
        }

        $phpClass = $context->scope->getPhpClassInDeeperScope($context->className);

        if (! $phpClass->exists() || ! $phpClass->getReflection()->isEnum()) {
            return null;
        }

        return new ArrayType(itemType: $phpClass->resolveType());
    }
}
