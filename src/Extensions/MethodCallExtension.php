<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\MethodCallContext;
use AutoDoc\DataTypes\Type;


abstract class MethodCallExtension
{
    public function getReturnType(MethodCallContext $context): ?Type
    {
        return null;
    }

    public function getRequestType(MethodCallContext $context): ?Type
    {
        return null;
    }

    public function narrowTypeFromCondition(MethodCallContext $context, bool $negated): void {}
}
