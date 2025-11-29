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
        $type = Type::resolveFromReflection($this->reflection, $this->scope);

        $type->addDescription($this->description);

        return $type;
    }
}
