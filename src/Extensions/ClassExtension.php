<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\DataTypes\Type;


abstract class ClassExtension
{
    /**
     * @param PhpClass<object> $phpClass
     */
    public function getReturnType(PhpClass $phpClass): ?Type
    {
        return null;
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getPropertyType(PhpClass $phpClass, string $propertyName): ?Type
    {
        return null;
    }

    /**
     * @param PhpClass<object> $phpClass
     */
    public function getRequestType(PhpClass $phpClass): ?Type
    {
        return null;
    }
}
