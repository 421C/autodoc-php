<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\DataTypes\Type;


abstract class FuncCallExtension
{
    public function getReturnType(FuncCallContext $context): ?Type
    {
        return null;
    }

    public function getRequestType(FuncCallContext $context): ?Type
    {
        return null;
    }
}
