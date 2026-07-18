<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\DataTypes\Type;


abstract class FuncCallExtension
{
    public function getReturnType(FuncCallContext $context): ?Type
    {
        return null;
    }

    public function handleSideEffect(FuncCallContext $context): void {}

    public function narrowTypeFromCondition(FuncCallContext $context, bool $negated): void {}
}
