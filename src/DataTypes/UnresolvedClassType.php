<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;

class UnresolvedClassType extends UnresolvedType
{
    public function __construct(
        /**
         * @var class-string
         */
        public ?string $className,
        public Scope $scope,
        public ?string $description = null,

        /**
         * @var array<?UnresolvedType>
         */
        public array $templateTypeValues = [],
    ) {}


    public function resolve(): Type
    {
        if ($this->className) {
            $phpClass = $this->scope->getPhpClassInDeeperScope($this->className);

            if ($this->templateTypeValues) {
                $phpClass->setTemplateTypeValues($this->templateTypeValues);
            }

            $resolvedType = $phpClass->resolveType();

        } else {
            $resolvedType = new ObjectType;
        }

        $resolvedType->addDescription($this->description);
        $resolvedType->examples = $this->examples ?: $resolvedType->examples;
        $resolvedType->required = $this->required ?: $resolvedType->required;

        return $resolvedType;
    }
}
