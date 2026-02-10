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
            return [
                ...array_filter([
                    'type' => 'object',
                    'properties' => array_combine(
                        array_map(fn ($key) => (string) $key, array_keys($this->shape)),
                        array_map(fn ($prop) => $prop->toSchema($config), $this->shape)
                    ),
                    'description' => $this->description,
                    'examples' => $this->examples ? array_values($this->examples) : null,
                    'required' => array_values(array_filter(
                        array_map(
                            fn ($prop, $propName) => $prop->required ? (string) $propName : null,
                            $this->shape,
                            array_keys($this->shape),
                        ),
                        fn ($propName) => $propName !== null,
                    )),
                    'deprecated' => $this->deprecated,
                    'x-deprecated-description' => $this->deprecatedDescription,
                ]),
            ];
        }

        $this->keyType = $this->keyType?->unwrapType($config);
        $this->itemType = $this->itemType?->unwrapType($config);

        $keyTypes = $this->keyType instanceof UnionType ? $this->keyType->types : array_filter([$this->keyType]);
        $hasStringKeys = false;

        foreach ($keyTypes as $keyType) {
            if (! ($keyType instanceof IntegerType || $keyType instanceof NumberType)) {
                $hasStringKeys = true;
                break;
            }
        }

        if ($hasStringKeys) {
            return array_filter([
                'type' => 'object',
                'additionalProperties' => ($this->itemType ?? new UnknownType)->toSchema($config),
                'description' => $this->description,
                'examples' => $this->examples ? array_values($this->examples) : null,
                'minProperties' => $this->minItems,
                'maxProperties' => $this->maxItems,
                'deprecated' => $this->deprecated,
                'x-deprecated-description' => $this->deprecatedDescription,
            ]);

        } else {
            return array_filter([
                'type' => 'array',
                'items' => ($this->itemType ?? new UnknownType)->toSchema($config),
                'description' => $this->description,
                'examples' => $this->examples ? array_values($this->examples) : null,
                'minItems' => $this->minItems,
                'maxItems' => $this->maxItems,
                'deprecated' => $this->deprecated,
                'x-deprecated-description' => $this->deprecatedDescription,
            ]);
        }
    }


    public function convertShapeToTypePair(?Config $config = null, bool $removePossibleItemValues = false): self
    {
        if ($this->shape) {
            $keyTypes = [];
            $itemTypes = [];

            $showValuesForScalarTypes = $config?->data['openapi']['show_values_for_scalar_types'] ?? false;

            foreach ($this->shape as $key => $value) {
                if (is_int($key)) {
                    $keyTypes[] = new IntegerType($showValuesForScalarTypes ? $key : null);

                } else {
                    $keyTypes[] = new StringType($showValuesForScalarTypes ? $key : null);
                }

                $itemTypes[] = $value->unwrapType($config);
            }

            $this->keyType = (new UnionType($keyTypes))->unwrapType($config)->unwrapType($config);
            $this->itemType = (new UnionType($itemTypes))->unwrapType($config)->unwrapType($config);
            $this->shape = [];
        }

        if ($removePossibleItemValues) {
            $removeItemValues = function (Type $type) use (&$removeItemValues): Type {
                if ($type instanceof IntegerType
                    || $type instanceof FloatType
                    || $type instanceof NumberType
                    || $type instanceof StringType
                ) {
                    $type->value = null;
                }

                if ($type instanceof UnionType || $type instanceof IntersectionType) {
                    $type->types = array_map($removeItemValues, $type->types);
                }

                return $type;
            };

            if ($this->itemType) {
                $this->itemType = $removeItemValues($this->itemType);
            }

            if ($this->keyType) {
                $this->keyType = $removeItemValues($this->keyType);
            }
        }

        return $this;
    }


    public function addItemToArray(int|string|null $key, Type $itemType, ?Config $config = null): self
    {
        if ($key === null || is_int($key)) {
            $this->convertShapeToTypePair($config);
            $this->keyType = (new UnionType(array_values(array_filter([$this->keyType, new IntegerType]))))->unwrapType($config);

            if ($this->itemType === null) {
                $this->itemType = $itemType;
            }

        } else if ($this->shape || $this->itemType === null) {
            $this->shape[$key] = $itemType;

        } else {
            $this->keyType = (new UnionType(array_values(array_filter([$this->keyType, new StringType]))))->unwrapType($config);
            $this->itemType = (new UnionType([$this->itemType, $itemType]))->unwrapType($config);
        }

        return $this;
    }
}
