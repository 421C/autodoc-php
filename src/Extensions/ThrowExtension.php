<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\DataTypes\Type;


abstract class ThrowExtension
{
    public function getReturnType(ThrowContext $context): ?Type
    {
        return null;
    }
}
