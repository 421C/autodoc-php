<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\PermissionEnum;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;

/**
 * Tests for basic response types: scalars, arrays, objects, PHPDoc return types.
 */
class BasicResponsesController
{
    /**
     * Array shape from return tag
     *
     * Reads response schema from `@return` tag.
     *
     * @return array{
     *     success: bool,
     *     data?: string,
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Array shape from return tag',
        'description' => 'Reads response schema from `@return` tag.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'success' => [
                                    'type' => 'boolean',
                                ],
                                'data' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'success',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayShapeFromReturnTag(): array
    {
        return ['success' => true];
    }


    /**
     * Nested object with enum and class
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Nested object with enum and class',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'a' => [
                                            'type' => 'integer',
                                            'description' => '[StateEnum](#/schemas/StateEnum)' . "\n\n" . 'Description for `a`.',
                                            'enum' => [
                                                1,
                                                2,
                                            ],
                                        ],
                                        'b' => [
                                            'type' => 'null',
                                        ],
                                        'c' => [
                                            'items' => [
                                                'enum' => [
                                                    1,
                                                    2,
                                                    3,
                                                ],
                                                'type' => 'integer',
                                            ],
                                            'type' => 'array',
                                        ],
                                        'd' => [
                                            'format' => 'float',
                                            'type' => 'number',
                                            'const' => 0.444,
                                        ],
                                        'e' => [
                                            'const' => '',
                                            'type' => 'string',
                                        ],
                                        'f' => [
                                            'type' => ['string', 'null'],
                                        ],
                                        'g' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'n' => [
                                                    'type' => ['integer', 'null'],
                                                ],
                                            ],
                                            'required' => [
                                                'n',
                                            ],
                                        ],
                                    ],
                                    'required' => [
                                        'a',
                                        'b',
                                        'c',
                                        'd',
                                        'e',
                                        'f',
                                        'g',
                                    ],
                                ],
                            ],
                            'required' => [
                                'data',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function nestedObjectWithEnumAndClass(): mixed
    {
        $var = [
            /**
             * Description for `a`.
             */
            'a' => StateEnum::Two,
            'b' => null,
            'c' => [1, 2, 3],
            'd' => 0.444,
            'e' => '',

            /** @var ?string */
            'f' => null,
        ];

        $var['g'] = new SimpleClass(100);

        return [
            'data' => $var,
        ];
    }


    /**
     * @return object{'x': 1.5|2.5, 'y'?: '0.5', z?: 0}[]
     *
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'x' => [
                                        'type' => 'number',
                                        'format' => 'float',
                                        'enum' => [
                                            1.5,
                                            2.5,
                                        ],
                                    ],
                                    'y' => [
                                        'const' => '0.5',
                                        'type' => 'string',
                                    ],
                                    'z' => [
                                        'const' => 0,
                                        'type' => 'integer',
                                    ],
                                ],
                                'required' => [
                                    'x',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function objectArrayWithOptionalProperties() {}


    /**
     * @return array{true, false, null}
     *
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                [
                                    'type' => 'boolean',
                                ],
                                [
                                    'type' => 'boolean',
                                ],
                                [
                                    'type' => 'null',
                                ],
                            ],
                            'required' => [
                                '0',
                                '1',
                                '2',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function tupleWithBoolsAndNull() {}


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => [
                                    'type' => 'string',
                                ],
                                'encoded' => [
                                    'type' => 'string',
                                    'format' => 'byte',
                                ],
                                'count' => [
                                    'type' => 'integer',
                                ],
                                'enum' => [
                                    'type' => 'integer',
                                    'description' => '[StateEnum](#/schemas/StateEnum)',
                                    'enum' => [
                                        1,
                                        2,
                                    ],
                                ],
                            ],
                            'required' => [
                                'text',
                                'encoded',
                                'count',
                                'enum',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function objectWithEncodedString(string $text, int $count, StateEnum $enum): object
    {
        return (object) [
            'text' => $text,
            'encoded' => base64_encode($text),
            'count' => $count,
            'enum' => $enum,
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function booleanResponse(): bool
    {
        return true;
    }


    /**
     * @phpstan-ignore missingType.return
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [])]
    public function bareReturnDocumentsNoResponseBody()
    {
        return;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'items' => [
                                'type' => 'string',
                            ],
                            'type' => 'array',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function stringArrayFromStringOperations(): mixed
    {
        $str = 'test';
        $str = mb_strtolower($str);

        $str = $str . $str;

        $str = [
            $str,
            $str,
        ];

        return $str;
    }


    // The recursion never resolves to a concrete type, and `mixed` must not
    // fabricate one via its implied null — an unknown return documents no response.
    #[ExpectedOperationSchema('showValuesForScalarTypes', [])]
    public function recursiveMethodReturnsNull(): mixed
    {
        return $this->recursiveMethodReturnsNull();
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'description' => '[PermissionEnum](#/schemas/PermissionEnum)',
                            'enum' => [
                                'read',
                                'write',
                            ],
                            'type' => 'string',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function enumWithIgnoredCase(): PermissionEnum
    {
        return PermissionEnum::None;
    }


    /**
     * Array key doc type
     *
     * `array-key` is `int|string`, so as a key it puts the array on the object branch.
     *
     * @return array{
     *     key: array-key,
     *     nullableKey: ?array-key,
     *     keyed: array<array-key, string>,
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Array key doc type',
        'description' => '`array-key` is `int|string`, so as a key it puts the array on the object branch.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'key' => [
                                    'type' => [
                                        'integer',
                                        'string',
                                    ],
                                ],
                                'nullableKey' => [
                                    'type' => [
                                        'integer',
                                        'string',
                                        'null',
                                    ],
                                ],
                                'keyed' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                            'required' => [
                                'key',
                                'nullableKey',
                                'keyed',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayKeyDocType(): array
    {
        return [
            'key' => 'id',
            'nullableKey' => null,
            'keyed' => ['id' => 'first'],
        ];
    }
}
