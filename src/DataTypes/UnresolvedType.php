<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

abstract class UnresolvedType extends Type
{
    abstract public function resolve(): Type;

    public function toSchema(Config $config): array
    {
        return $this->resolve()->toSchema($config);
    }
}
