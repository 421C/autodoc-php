<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class ArrayType extends Type
{
    public function __construct(
        public ?Type $itemType = null,
        public ?Type $keyType = null,

        /**
         * @var array<int|string, Type>
         */
        public array $shape = [],

        /**
         * For collection classes that are resolved as arrays.
         *
         * @var ?class-string<object>
         */
        public ?string $className = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
    ) {}


    public function toSchema(?Config $config = null): array
    {
        if ($this->shape) {
            return array_filter([
                'type' => 'object',
                'properties' => array_map(fn ($prop) => $prop->toSchema($config), $this->shape),
                'description' => $this->description,
                'examples' => $this->examples,
                'required' => array_values(array_filter(
                    array_map(
                        fn ($prop, $propName) => $prop->required ? $propName : null,
                        $this->shape,
                        array_keys($this->shape),
                    ),
                    fn ($propName) => $propName !== null,
                )),
            ]);
        }

        $this->keyType = $this->keyType?->unwrapType($config);
        $this->itemType = $this->itemType?->unwrapType($config);

        if ($this->keyType && !($this->keyType instanceof IntegerType)) {
            return array_filter([
                'type' => 'object',
                'additionalProperties' => ($this->itemType ?? new UnknownType)->toSchema($config),
                'description' => $this->description,
                'examples' => $this->examples,
                'minProperties' => $this->minItems,
                'maxProperties' => $this->maxItems,
            ]);

        } else {
            return array_filter([
                'type' => 'array',
                'items' => ($this->itemType ?? new UnknownType)->toSchema($config),
                'description' => $this->description,
                'examples' => $this->examples,
                'minItems' => $this->minItems,
                'maxItems' => $this->maxItems,
            ]);
        }
    }
}
