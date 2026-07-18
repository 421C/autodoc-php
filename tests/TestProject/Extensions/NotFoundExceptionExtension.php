<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\ThrowContext;
use AutoDoc\Extensions\ThrowExtension;
use AutoDoc\Tests\TestProject\Exceptions\NotFoundException;

class NotFoundExceptionExtension extends ThrowExtension
{
    public function getReturnType(ThrowContext $context): ?Type
    {
        if ($context->getThrownClassName() === NotFoundException::class) {
            return $context->scope->getPhpClass(NotFoundException::class)->getMethod('render')->getReturnType();
        }

        return null;
    }
}
