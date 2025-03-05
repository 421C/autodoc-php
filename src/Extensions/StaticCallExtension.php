<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr\StaticCall;


abstract class StaticCallExtension
{
    public function getReturnType(StaticCall $methodCall, Scope $scope): ?Type
    {
        return null;
    }

    public function getRequestType(StaticCall $methodCall, Scope $scope): ?Type
    {
        return null;
    }
}
