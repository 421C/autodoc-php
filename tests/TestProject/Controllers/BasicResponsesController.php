<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use ArrayIterator;
use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\ClassWithDeprecatedProperties;
use AutoDoc\Tests\TestProject\Entities\ClassWithDocumentedDateAndEnumProperties;
use AutoDoc\Tests\TestProject\Entities\ClassWithDocumentedVarTagProperties;
use AutoDoc\Tests\TestProject\Entities\PermissionEnum;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;
use Traversable;

/**
 * Tests for basic response types: scalars, arrays, objects, PHPDoc return types.
 */
class BasicResponsesController
{
    private const array PLANET = [
        'name' => 'Mars',
        'moons' => ['Phobos', 'Deimos'],
    ];

    private const array IDS = [1, 2, 3];

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
                            'type' => 'array',
                            'prefixItems' => [
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
                            'minItems' => 3,
                            'maxItems' => 3,
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
                            'type' => 'array',
                            'prefixItems' => [
                                [
                                    'const' => 'a',
                                    'type' => 'string',
                                ],
                                [
                                    'const' => 2,
                                    'type' => 'integer',
                                ],
                            ],
                            'minItems' => 2,
                            'maxItems' => 2,
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function tupleWithExplicitListKeys(): mixed
    {
        return [0 => 'a', 1 => 2];
    }


    /**
     * @return array{0: int, 1?: string}
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
                            'prefixItems' => [
                                [
                                    'type' => 'integer',
                                ],
                                [
                                    'type' => 'string',
                                ],
                            ],
                            'minItems' => 1,
                            'maxItems' => 2,
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function tupleWithTrailingOptionalEntry() {}


    /**
     * @return array{0?: int, 1: string}
     *
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'type' => 'array',
                                    'prefixItems' => [
                                        ['type' => 'integer'],
                                        ['type' => 'string'],
                                    ],
                                    'minItems' => 2,
                                    'maxItems' => 2,
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        0 => [
                                            'type' => 'integer',
                                        ],
                                        1 => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        '1',
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
    public function listKeyedShapeWithOptionalFirstEntry() {}


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'anyOf' => [
                                    [
                                        'const' => 'a',
                                        'type' => 'string',
                                    ],
                                    [
                                        'const' => 2,
                                        'type' => 'integer',
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
    #[ExpectedOperationSchema('resolvePartialArrayShapes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'prefixItems' => [
                                [
                                    'type' => 'string',
                                ],
                                [
                                    'type' => 'integer',
                                ],
                            ],
                            'minItems' => 2,
                            'maxItems' => 2,
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function keylessArrayLiteral(): mixed
    {
        return ['a', 2];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => [
                                    'type' => 'string',
                                    'const' => 'Mars',
                                ],
                                'moons' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                        'enum' => ['Phobos', 'Deimos'],
                                    ],
                                ],
                            ],
                            'required' => [
                                'name',
                                'moons',
                            ],
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
                            'type' => 'object',
                            'properties' => [
                                'name' => [
                                    'type' => 'string',
                                ],
                                'moons' => [
                                    'type' => 'array',
                                    'prefixItems' => [
                                        [
                                            'type' => 'string',
                                        ],
                                        [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'minItems' => 2,
                                    'maxItems' => 2,
                                ],
                            ],
                            'required' => [
                                'name',
                                'moons',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function associativeArrayConstant(): mixed
    {
        return self::PLANET;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'integer',
                                'enum' => [1, 2, 3],
                            ],
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
                            'type' => 'array',
                            'prefixItems' => [
                                [
                                    'type' => 'integer',
                                ],
                                [
                                    'type' => 'integer',
                                ],
                                [
                                    'type' => 'integer',
                                ],
                            ],
                            'minItems' => 3,
                            'maxItems' => 3,
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function listArrayConstant(): mixed
    {
        return self::IDS;
    }


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
        /** @phpstan-ignore return.type */
        return $this->opaquePayload();
    }


    /**
     * Literal refining the return shape
     *
     * The returned literal fits the `@return` shape, so its values are documented, as for a scalar return.
     *
     * @return array{
     *     status: string,
     *     state: int|string,
     *     nested: array{count: int},
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Literal refining the return shape',
        'description' => 'The returned literal fits the `@return` shape, so its values are documented, as for a scalar return.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'status' => [
                                    'type' => 'string',
                                    'const' => 'ok',
                                ],
                                'state' => [
                                    'anyOf' => [
                                        [
                                            'type' => 'integer',
                                            'const' => 0,
                                        ],
                                        [
                                            'type' => 'string',
                                            'const' => 'off',
                                        ],
                                    ],
                                ],
                                'nested' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'count' => [
                                            'type' => 'integer',
                                            'const' => 2,
                                        ],
                                    ],
                                    'required' => ['count'],
                                ],
                            ],
                            'required' => ['status', 'state', 'nested'],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function literalRefiningReturnShape(): array
    {
        return [
            'status' => 'ok',
            'state' => rand(0, 1) ? 0 : 'off',
            'nested' => ['count' => 2],
        ];
    }


    /**
     * Pseudo doc types
     *
     * `int-mask<1, 2>` is not an `int<1, 2>` range, and `Traversable` reads like `iterable`.
     *
     * @return array{
     *     intMask: int-mask<1, 2>,
     *     intMaskOf: int-mask-of<1|2>,
     *     callableStrings: list<callable-string>,
     *     nonEmptyScalar: non-empty-scalar,
     *     traversable: Traversable<string, int>,
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Pseudo doc types',
        'description' => '`int-mask<1, 2>` is not an `int<1, 2>` range, and `Traversable` reads like `iterable`.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'intMask' => [
                                    'type' => 'integer',
                                ],
                                'intMaskOf' => [
                                    'type' => 'integer',
                                ],
                                'callableStrings' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'nonEmptyScalar' => [
                                    'type' => [
                                        'number',
                                        'string',
                                        'boolean',
                                    ],
                                ],
                                'traversable' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'type' => 'integer',
                                    ],
                                ],
                            ],
                            'required' => [
                                'intMask',
                                'intMaskOf',
                                'callableStrings',
                                'nonEmptyScalar',
                                'traversable',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function pseudoDocTypes(): array
    {
        return [
            'intMask' => 3,
            'intMaskOf' => 3,
            'callableStrings' => ['strlen'],
            'nonEmptyScalar' => 1,
            'traversable' => new ArrayIterator(['id' => 1]),
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'plain' => [
                                    'type' => 'string',
                                    'description' => 'Plain var tag.',
                                ],
                                'nullable' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Nullable var tag.',
                                ],
                            ],
                            'required' => [
                                'plain',
                                'nullable',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function varTagPropertyDescriptions(): ClassWithDocumentedVarTagProperties
    {
        return new ClassWithDocumentedVarTagProperties;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'date' => [
                                    'type' => 'string',
                                    'format' => 'date-time',
                                    'description' => 'Plain date.',
                                    'examples' => ['2024-01-01T00:00:00Z'],
                                ],
                                'nullableDate' => [
                                    'type' => ['string', 'null'],
                                    'format' => 'date-time',
                                    'description' => 'Nullable date.',
                                    'examples' => ['2024-01-01T00:00:00Z'],
                                ],
                                'state' => [
                                    'type' => 'integer',
                                    'description' => '[StateEnum](#/schemas/StateEnum)' . "\n\n" . 'Plain enum.',
                                    'enum' => [
                                        1,
                                        2,
                                    ],
                                ],
                                'nullableState' => [
                                    'type' => ['integer', 'null'],
                                    'description' => '[StateEnum](#/schemas/StateEnum)' . "\n\n" . 'Nullable enum.',
                                    'enum' => [
                                        1,
                                        2,
                                        null,
                                    ],
                                ],
                            ],
                            'required' => [
                                'date',
                                'nullableDate',
                                'state',
                                'nullableState',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function dateAndEnumPropertyDescriptions(): ClassWithDocumentedDateAndEnumProperties
    {
        return new ClassWithDocumentedDateAndEnumProperties;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'plain' => [
                                    'type' => 'string',
                                    'deprecated' => true,
                                    'x-deprecated-description' => 'Use `slug`.',
                                ],
                                'nullable' => [
                                    'type' => ['string', 'null'],
                                    'deprecated' => true,
                                    'x-deprecated-description' => 'Use `slug`.',
                                ],
                                'date' => [
                                    'type' => 'string',
                                    'format' => 'date-time',
                                    'deprecated' => true,
                                    'x-deprecated-description' => 'Use `launchedAt`.',
                                ],
                                'nullableDate' => [
                                    'type' => ['string', 'null'],
                                    'format' => 'date-time',
                                    'deprecated' => true,
                                    'x-deprecated-description' => 'Use `launchedAt`.',
                                ],
                            ],
                            'required' => [
                                'plain',
                                'nullable',
                                'date',
                                'nullableDate',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function deprecatedPropertyMessages(): ClassWithDeprecatedProperties
    {
        return new ClassWithDeprecatedProperties;
    }


    /**
     * An unknown analyzed type leaves a route's PHPDoc `@return` type in place.
     */
    private function opaquePayload(): mixed
    {
        return json_decode('null', true);
    }
}
