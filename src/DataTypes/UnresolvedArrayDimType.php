<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;


class UnresolvedArrayDimType extends UnresolvedType
{
    public function __construct(
        public Type $potentialArrayType,

        /**
         * @var non-empty-list<int|string>
         */
        public array $readPath,
        public Scope $scope,
    ) {}


    public function resolve(): Type
    {
        $type = $this->scope->withPartialArraysResolvingAsShapes(
            fn () => $this->potentialArrayType instanceof UnresolvedVariableType
                ? $this->potentialArrayType->resolve($this->readPath)
                : $this->potentialArrayType->unwrapType($this->scope->config),
        );

        foreach ($this->readPath as $key) {
            $type = $this->scope->getTypeAtKey($type, $key);
        }

        return $type;
    }
}
