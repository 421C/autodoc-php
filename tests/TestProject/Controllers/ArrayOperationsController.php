<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Traits\TestTrait;

/**
 * Tests for array operations: array_map, array_filter, array_merge, etc.
 */
class ArrayOperationsController
{
    use TestTrait;

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'integer',
                                    'enum' => [
                                        1,
                                        2,
                                        3,
                                        4,
                                        5,
                                        6,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayMapWithMultipleArrays(): mixed
    {
        return array_map(null, [1, 2, 3], [4, 5, 6]);
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'anyOf' => [
                                        [
                                            'enum' => [
                                                1,
                                                2,
                                                3,
                                            ],
                                            'type' => 'integer',
                                        ],
                                        [
                                            'type' => 'string',
                                            'enum' => [
                                                'a',
                                                'b',
                                                'c',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayMapWithKeysAndValues(): mixed
    {
        $arr = ['a' => 1, 'b' => 2, 'c' => 3];

        return array_map(function ($value, $key) {
            return [$value, $key];
        }, $arr, array_keys($arr));
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'flip' => [
                                    'type' => 'array',
                                    'items' => [
                                        'enum' => [
                                            'a',
                                            'b',
                                            'c',
                                        ],
                                        'type' => 'string',
                                    ],
                                ],
                                'flip2' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'enum' => [
                                            1,
                                            2,
                                            3,
                                        ],
                                        'type' => 'integer',
                                    ],
                                ],
                                'filter' => [
                                    'items' => [
                                        'enum' => [
                                            'x',
                                            'c',
                                            'y',
                                        ],
                                        'type' => 'string',
                                    ],
                                    'type' => 'array',
                                ],
                                'mergeItems' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'anyOf' => [
                                            [
                                                'enum' => [
                                                    1,
                                                    2,
                                                    3,
                                                ],
                                                'type' => 'integer',
                                            ],
                                            [
                                                'enum' => [
                                                    'x',
                                                    'y',
                                                ],
                                                'type' => 'string',
                                            ],
                                        ],
                                    ],
                                ],
                                'mergeShape' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'a' => [
                                            'const' => 1,
                                            'type' => 'integer',
                                        ],
                                        'b' => [
                                            'const' => 2,
                                            'type' => 'integer',
                                        ],
                                        'c' => [
                                            'const' => 3,
                                            'type' => 'integer',
                                        ],
                                        'd' => [
                                            'const' => 4.1,
                                            'format' => 'float',
                                            'type' => 'number',
                                        ],
                                    ],
                                    'required' => [
                                        'a',
                                        'b',
                                        'c',
                                        'd',
                                    ],
                                ],
                            ],
                            'required' => [
                                'flip',
                                'flip2',
                                'filter',
                                'mergeShape',
                                'mergeItems',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayFlipFilterMerge(): mixed
    {
        $arr = ['a' => 1, 'b' => 2, 'c' => 3];

        return [
            'flip' => array_flip($arr),
            'flip2' => array_flip(array_flip($arr)),
            'filter' => array_filter(['x', 'c', rand() ? null : 'y']),
            'mergeShape' => array_merge($arr, [
                'd' => 4.1,
            ]),
            'mergeItems' => array_merge($arr, ['x', 'x', 'y']),
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'arr' => [
                                    'properties' => [
                                        'a' => [
                                            'const' => 1,
                                            'type' => 'integer',
                                        ],
                                        'b' => [
                                            'const' => 2,
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'a',
                                        'b',
                                    ],
                                    'type' => 'object',
                                ],
                                'val' => [
                                    'anyOf' => [
                                        [
                                            'enum' => [
                                                1,
                                                2,
                                            ],
                                            'type' => 'integer',
                                        ],
                                        [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                ],
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayFilterWithReset(): mixed
    {
        /** @phpstan-ignore arrayFilter.same */
        $arr = array_filter(['a' => 1, 'b' => 2]);

        $val = reset($arr);

        return compact('val', 'arr');
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'a' => [
                                    'type' => 'number',
                                ],
                                'arr' => [
                                    'items' => [
                                        'const' => 'a',
                                        'type' => 'string',
                                    ],
                                    'type' => 'array',
                                ],
                                'key' => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function compactWithDynamicKeys(): mixed
    {
        $a = 100 * 100;
        $name = 'a';

        $arr = [$name];
        $key = array_key_first($arr);

        return compact($arr, 'arr', [[['key']]]);
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'items' => [
                                'properties' => [
                                    'arr' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'a' => [
                                                'type' => 'integer',
                                            ],
                                            'b' => [
                                                'type' => 'string',
                                            ],
                                        ],
                                        'required' => [
                                            'a',
                                            'b',
                                        ],
                                    ],
                                    'number' => [
                                        'enum' => [
                                            1,
                                            2,
                                            3,
                                        ],
                                        'type' => 'integer',
                                    ],
                                ],
                                'required' => [
                                    'number',
                                    'arr',
                                ],
                                'type' => 'object',
                            ],
                            'type' => 'array',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayMapWithTraitMethod(): mixed
    {
        $numbers = [1, 2, 3];

        $result = array_map(
            fn ($number) => [
                'number' => $number,
                'arr' => $this->methodFromTrait(),
            ],
            $numbers,
        );

        /** @phpstan-ignore arrayValues.list */
        return array_values($result);
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'additionalProperties' => [
                                'type' => 'integer',
                                'enum' => [
                                    123,
                                    456,
                                ],
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    #[ExpectedOperationSchema('resolvePartialArrayShapes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                0 => [
                                    'type' => 'integer',
                                ],
                                'a' => [
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'a',
                                '0',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function mixedKeysObject(): mixed
    {
        return (object) [
            'a' => 123,
            456,
        ];
    }


    #[ExpectedOperationSchema('resolvePartialArrayShapes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayValuesOnAssocArray(): mixed
    {
        $numbers = [
            'a' => 1,
            'b' => 2,
        ];

        $numbers = array_values(
            $numbers,
        );

        return $numbers;
    }


    #[ExpectedOperationSchema('resolvePartialArrayShapes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'a' => [
                                    'type' => 'integer',
                                ],
                                'b' => [
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'a',
                                'b',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayMergeWithEmptyArray(): mixed
    {
        $numbers = rand(0, 1) ? [
            'a' => 1,
            'b' => 2,
        ] : null;

        return $numbers ?? [];
    }


    #[ExpectedOperationSchema('resolvePartialArrayShapes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'items' => [
                                'type' => 'integer',
                            ],
                            'type' => 'array',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function foreachWithNullCoalescingArray(): mixed
    {
        $numbers = rand(0, 1) ? [
            'a' => 1,
            'b' => 2,
        ] : null;

        $result = [];

        foreach ($numbers ?? [] as $number) {
            $result[] = $number;
        }

        return $result;
    }
}
