<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

abstract class UnresolvedType extends Type
{
    abstract public function resolve(): Type;

    public function toSchema(): array
    {
        return $this->resolve()->toSchema();
    }
}
