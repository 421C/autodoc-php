<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\PhpFunctionArgument;

class ObjectType extends Type
{
    public function __construct(
        /**
         * @var array<string, Type>
         */
        public array $properties = [],

        /**
         * @var ?class-string<object>
         */
        public ?string $className = null,
        public ?string $description = null,
        public ?Type $typeToDisplay = null,

        /**
         * @var array<PhpFunctionArgument>
         */
        public array $constructorArgs = [],
    ) {}


    public function toSchema(): array
    {
        if ($this->typeToDisplay) {
            return $this->typeToDisplay->toSchema();
        }

        return array_filter([
            'type' => 'object',
            'properties' => array_map(fn ($prop) => $prop->toSchema(), $this->properties),
            'description' => $this->description,
            'examples' => $this->examples,
            'required' => array_values(array_filter(
                array_map(
                    fn ($prop, $propName) => $prop->required ? $propName : null,
                    $this->properties,
                    array_keys($this->properties),
                )
            )),
        ]);
    }
}
