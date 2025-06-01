<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\PhpFunctionArgument;
use AutoDoc\Config;

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

        /**
         * Properties that do not appear in the generated documentation
         * unless specifically accessed or referenced.
         *
         * @var array<string, Type>
         */
        public array $hiddenProperties = [],
    ) {}


    public function toSchema(?Config $config = null): array
    {
        if ($this->typeToDisplay) {
            return $this->typeToDisplay->toSchema($config);
        }

        return array_filter([
            'type' => 'object',
            'properties' => array_map(fn ($prop) => $prop->toSchema($config), $this->properties),
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
