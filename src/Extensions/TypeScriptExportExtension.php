<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\DataTypes\Type;

abstract class TypeScriptExportExtension
{
    /**
     * @param PhpClass<object> $phpClass
     */
    abstract public function handle(PhpClass $phpClass, Type $type): ?Type;
}
