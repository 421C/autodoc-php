<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\StaticCallContext;
use AutoDoc\DataTypes\Type;


abstract class StaticCallExtension
{
    public function getReturnType(StaticCallContext $context): ?Type
    {
        return null;
    }

    public function getRequestType(StaticCallContext $context): ?Type
    {
        return null;
    }

    public function narrowTypeFromCondition(StaticCallContext $context, bool $negated): void {}
}
