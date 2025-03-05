<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr\FuncCall;


abstract class FuncCallExtension
{
    public function getReturnType(FuncCall $funcCall, Scope $scope): ?Type
    {
        return null;
    }

    public function getRequestType(FuncCall $funcCall, Scope $scope): ?Type
    {
        return null;
    }
}
