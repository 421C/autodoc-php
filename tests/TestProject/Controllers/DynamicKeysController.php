<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\RocketCategory;

/**
 * Tests for dynamic array keys and computed property names.
 */
class DynamicKeysController
{
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                15 => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'anyOf' => [
                                            [
                                                'const' => 4.21,
                                                'format' => 'float',
                                                'type' => 'number',
                                            ],
                                            [
                                                'description' => '[RocketCategory](#/schemas/RocketCategory)',
                                                'enum' => [
                                                    'Big',
                                                    'Small',
                                                ],
                                                'type' => 'string',
                                            ],
                                            [
                                                'type' => 'boolean',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            'required' => [
                                '15',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function integerKeyWithMixedValues(): mixed
    {
        $i = 15;

        return [
            $i => [
                'a' => 4.21,
                'b' => true,
                strtolower('C') => RocketCategory::Big,
            ],
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'anotherProp' => [
                                    'properties' => [
                                        'deeper' => [
                                            'properties' => [
                                                'o' => [
                                                    'properties' => [
                                                        'x' => [
                                                            'properties' => [
                                                                'y' => [
                                                                    'properties' => [
                                                                        'key' => [
                                                                            'type' => 'boolean',
                                                                        ],
                                                                    ],
                                                                    'required' => [
                                                                        'key',
                                                                    ],
                                                                    'type' => 'object',
                                                                ],
                                                            ],
                                                            'required' => [
                                                                'y',
                                                            ],
                                                            'type' => 'object',
                                                        ],
                                                    ],
                                                    'required' => [
                                                        'x',
                                                    ],
                                                    'type' => 'object',
                                                ],
                                            ],
                                            'required' => [
                                                'o',
                                            ],
                                            'type' => 'object',
                                        ],
                                    ],
                                    'required' => [
                                        'deeper',
                                    ],
                                    'type' => 'object',
                                ],
                                'data' => [
                                    'properties' => [
                                        'second 1' => [
                                            'items' => [
                                                'const' => 50,
                                                'type' => 'integer',
                                            ],
                                            'type' => 'array',
                                        ],
                                        'second 2' => [
                                            'items' => [
                                                'const' => 50,
                                                'type' => 'integer',
                                            ],
                                            'type' => 'array',
                                        ],
                                    ],
                                    'required' => [
                                        'second 1',
                                        'second 2',
                                    ],
                                    'type' => 'object',
                                ],
                            ],
                            'required' => [
                                'data',
                                'anotherProp',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function deeplyNestedPropertyAccess(): mixed
    {
        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $secondArray = $this->emptyArrayCopiedAndModified()['second'];

        $obj = $this->getGenericClassInstanceWithoutPhpDoc(GenericClass::class, $secondArray);

        /** @phpstan-ignore-next-line */
        $obj->anotherProp->deeper['o']['x']->y['key'] = false;

        return $obj;
    }


    private function emptyArrayCopiedAndModified(): mixed
    {
        $empty = [];
        $nonEmpty = $empty;
        $nonEmpty[] = 50;

        $array = [
            'first' => [
                'first 1' => $empty,
                'first 2' => $empty,
            ],
            'second' => [
                'second 1' => $nonEmpty,
            ],
        ];

        /** @phpstan-ignore-next-line */
        $array['second']['second 2'][] = $array['second']['second 1'][0];

        return $array;
    }

    private function getGenericClassInstanceWithoutPhpDoc(string $className, mixed $classConstructorParam): mixed
    {
        return new $className($classConstructorParam);
    }
}
