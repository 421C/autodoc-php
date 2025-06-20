<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;
use AutoDoc\DataTypes\Traits\WithMergeableTypes;


class UnionType extends Type
{
    use WithMergeableTypes;

    public function __construct(
        /**
         * @var Type[]
         */
        public array $types = [],
    ) {}


    public function toSchema(?Config $config = null): array
    {
        $type = $this->unwrapType($config);

        if (! ($type instanceof UnionType)) {
            return $type->toSchema($config);
        }

        if (count($this->types) === 2) {
            $nullableType = null;

            if ($this->types[0] instanceof NullType) {
                $nullableType = $this->types[1];

            } else if ($this->types[1] instanceof NullType) {
                $nullableType = $this->types[0];
            }

            if ($nullableType) {
                if (! $nullableType->description) {
                    $nullableType->description = $this->description;
                }

                if (! $nullableType->examples) {
                    $nullableType->examples = $this->examples;
                }

                $schema = $nullableType->toSchema($config);

                if (isset($schema['type'])) {
                    $schema['type'] = [$schema['type'], 'null'];

                    return $schema;

                } else if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
                    $schema['anyOf'][] = ['type' => 'null'];

                    return $schema;
                }
            }
        }


        $simpleSchemaTypeNames = [];
        $uniqueTypeSchemas = [];

        foreach ($this->types as $type) {
            $schema = $type->toSchema($config);

            if (isset($schema['anyOf'])) {
                /** @var array<array<mixed>> */
                $schemas = $schema['anyOf'];

            } else {
                $schemas = [$schema];
            }

            foreach ($schemas as $schema) {
                $isTypeTheOnlyProperty = isset($schema['type']) && count($schema) === 1;

                if ($isTypeTheOnlyProperty) {
                    if (is_array($schema['type'])) {
                        foreach ($schema['type'] as $subTypeName) {
                            if (is_array($subTypeName)) {
                                foreach ($subTypeName as $name) {
                                    $simpleSchemaTypeNames[$name] = 1;
                                }

                            } else {
                                $simpleSchemaTypeNames[$subTypeName] = 1;
                            }
                        }

                    } else if (is_string($schema['type'])) {
                        $simpleSchemaTypeNames[$schema['type']] = 1;
                    }

                } else {
                    $uniqueTypeSchemas[] = $schema;
                }
            }
        }

        $uniqueTypeSchemas = $this->deepUnique($uniqueTypeSchemas);

        $typeSchemas = array_merge(
            array_filter($uniqueTypeSchemas, function ($schema) use ($simpleSchemaTypeNames) {
                if (empty($schema['type'])) {
                    return false;
                }

                if (is_array($schema['type']) || in_array($schema['type'], ['array', 'object'])) {
                    return true;
                }

                return ! isset($simpleSchemaTypeNames[$schema['type']]);
            }),
            array_map(fn ($typeName) => ['type' => $typeName], array_keys($simpleSchemaTypeNames)),
        );

        if (count($typeSchemas) === 1) {
            return $typeSchemas[0];
        }


        $canOutputTypesAsArray = true;

        foreach ($typeSchemas as $schema) {
            $isTypeTheOnlyProperty = isset($schema['type']) && count($schema) === 1;

            if (! $isTypeTheOnlyProperty) {
                $canOutputTypesAsArray = false;
                break;
            }
        }

        if ($canOutputTypesAsArray) {
            return [
                'type' => array_column($typeSchemas, 'type'),
            ];
        }


        return [
            'anyOf' => $typeSchemas,
        ];
    }


    /**
     * @template T
     *
     * @param array<T> $array
     * @return array<T>
     */
    private function deepUnique(array $array): array
    {
        $serialized = array_map('serialize', $array);
        $unique = array_unique($serialized);

        return array_intersect_key($array, $unique);
    }
}
