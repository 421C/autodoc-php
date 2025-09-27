<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\ThrowExtension;
use AutoDoc\Tests\TestProject\Exceptions\NotFoundException;
use PhpParser\Node;

class NotFoundExceptionExtension extends ThrowExtension
{
    public function getReturnType(Node\Expr $expr, Scope $scope): ?Type
    {
        $thrownType = $scope->resolveType($expr);

        if ($thrownType instanceof ObjectType
            && $thrownType->className === NotFoundException::class
        ) {
            return $scope->getPhpClass($thrownType->className)->getMethod('render')->getReturnType();
        }

        return null;
    }
}
