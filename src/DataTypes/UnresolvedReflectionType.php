<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;
use ReflectionType;


class UnresolvedReflectionType extends UnresolvedType
{
    public function __construct(
        public ReflectionType $reflection,
        public Scope $scope,
        public ?string $description = null,
    ) {}


    public function resolve(): Type
    {
        return Type::resolveFromReflection($this->reflection, $this->scope);
    }
}
