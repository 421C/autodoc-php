<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr\MethodCall;


abstract class MethodCallExtension
{
    public function getReturnType(MethodCall $methodCall, Scope $scope): ?Type
    {
        return null;
    }

    public function getRequestType(MethodCall $methodCall, Scope $scope): ?Type
    {
        return null;
    }
}
