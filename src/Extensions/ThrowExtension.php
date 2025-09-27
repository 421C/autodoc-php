<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr;


abstract class ThrowExtension
{
    public function getReturnType(Expr $expression, Scope $scope): ?Type
    {
        return null;
    }
}
