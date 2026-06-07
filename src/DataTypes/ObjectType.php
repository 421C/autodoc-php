<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\ArgumentList;
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
         * Arguments passed to the constructor when this type was created from
         * a `new X(...)` expression (or attached by an extension). Lazily
         * resolved and scope-aware; null when unknown.
         */
        public ?ArgumentList $constructorArgs = null,

        /**
         * Properties that do not appear in the generated documentation
         * unless specifically accessed or referenced.
         *
         * @var array<string, Type>
         */
        public array $hiddenProperties = [],
    ) {}


    public function toSchema(Config $config): array
    {
        if ($this->typeToDisplay) {
            $this->typeToDisplay->required = $this->typeToDisplay->required || $this->required;
            $this->typeToDisplay->deprecated = $this->typeToDisplay->deprecated || $this->deprecated;

            return $this->typeToDisplay->toSchema($config);
        }

        return array_filter([
            'type' => 'object',
            'properties' => array_combine(
                array_map(fn ($key) => (string) $key, array_keys($this->properties)),
                array_map(fn ($prop) => $prop->toSchema($config), $this->properties)
            ),
            'description' => $this->description,
            'examples' => $this->examples ? array_values($this->examples) : null,
            'required' => array_values(array_filter(
                array_map(
                    fn ($prop, $propName) => $prop->required ? (string) $propName : null,
                    $this->properties,
                    array_keys($this->properties),
                )
            )),
            'deprecated' => $this->deprecated,
            'x-deprecated-description' => $this->deprecatedDescription,
        ]);
    }
}
