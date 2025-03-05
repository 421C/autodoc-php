<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;

/**
 * @property class-string $className
 */
class UnresolvedClassType extends UnresolvedType
{
    public function __construct(
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
            $resolvedType = new ObjectType(className: $this->className);
        }

        $resolvedType->description = $resolvedType->description ?: $this->description;
        $resolvedType->examples = $resolvedType->examples ?: $this->examples;
        $resolvedType->required = $resolvedType->required ?: $this->required;

        return $resolvedType;
    }
}
