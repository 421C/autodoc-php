<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\ClassThatRepresentsAssocArray;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use AutoDoc\Tests\TestProject\Entities\RocketCategory;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;
use AutoDoc\Tests\TestProject\Exceptions\NotFoundException;
use AutoDoc\Tests\TestProject\Traits\TestTrait;

class TestController
{
    use TestTrait;

    /**
     * Route 1
     *
     * Reads response schema from `@return` tag.
     *
     * @return array{
     *     success: bool,
     *     data?: string,
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Route 1',
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
    public function route1(): array
    {
        return ['success' => true];
    }


    /**
     * Route 2
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Route 2',
        'description' => '',
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
    public function route2(): mixed
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
     * Route 3
     *
     * @phpstan-ignore missingType.return
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Route 3',
        'description' => '',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'n' => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                ],
                            ],
                            'required' => [
                                'n',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route3()
    {
        $classString = SimpleClass::class;

        return $this->getClassInstance($classString);
    }


    /**
     * Route 4
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Route 4',
        'description' => '',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'null',
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
    public function route4(): object
    {
        return $this->getGenericClassInstance(GenericClass::class, null);
    }


    /**
     * Route 5
     *
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Route 5',
        'description' => '',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'integer',
                                    ],
                                    'n' => [
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
    public function route5(): array
    {
        $classString = GenericSubClass::class;

        return [
            $this->getGenericClassInstance($classString, 1),
            $this->getGenericClassInstanceWithoutPhpDoc($classString, 2),
        ];
    }


    /**
     * @phpstan-ignore missingType.return
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'anyOf' => [
                                            [
                                                'const' => 'abc',
                                                'type' => 'string',
                                            ],
                                            [
                                                'const' => 123,
                                                'type' => 'integer',
                                            ],
                                        ],
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
    public function route6()
    {
        return new GenericClass([
            'abc',
            123,
        ]);
    }


    /**
     * @return object{'x': 1.5|2.5, 'y'?: '0.5', z?: 0}[]
     *
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
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
    public function route7() {}


    /**
     * @return array{true, false, null}
     *
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
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
                                0,
                                1,
                                2,
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route8() {}


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
    public function route9(string $text, int $count, StateEnum $enum): object
    {
        return (object) [
            'text' => $text,
            'encoded' => base64_encode($text),
            'count' => $count,
            'enum' => $enum,
        ];
    }


    /**
     * @template TVal of array{0|1, class-string}
     *
     * @param TVal $value
     *
     * @response TVal
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                [
                                    'type' => 'integer',
                                    'enum' => [
                                        0,
                                        1,
                                    ],
                                ],
                                [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                0,
                                1,
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route10(mixed $value): object
    {
        return (object) [];
    }


    /**
     * @param SimpleClass $value
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
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
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route11($value): object
    {
        return $value;
    }


    /**
     * @param $value Property description that is not going to be visible in response schema.
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
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
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route12(SimpleClass $value): object
    {
        return $value;
    }


    /**
     * @request-query filter {type: string[]}
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'parameters' => [
            [
                'in' => 'query',
                'name' => 'filter',
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ],
    ])]
    public function route13(): void {}


    /**
     * @request-header Authorization {required: true, description: 'Authorization header'}
     * @request-header x-state {description: 'Status', deprecated: true, type: StateEnum}
     *
     * @request object{
     *     data: array<string, array{
     *         id: int,
     *         name?: string,
     *     }>
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'parameters' => [
            [
                'in' => 'header',
                'name' => 'Authorization',
                'description' => 'Authorization header',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'in' => 'header',
                'name' => 'x-state',
                'description' => 'Status',
                'deprecated' => true,
                'schema' => [
                    'description' => '[StateEnum](#/schemas/StateEnum)',
                    'enum' => [
                        1,
                        2,
                    ],
                    'type' => 'integer',
                ],
            ],
        ],
        'requestBody' => [
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'additionalProperties' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => [
                                            'type' => 'integer',
                                        ],
                                        'name' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'id',
                                    ],
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
            'required' => false,
        ],
    ])]
    public function route14(): void {}


    /**
     * @request-cookie CSRF {description: 'CSRF token'}
     * @request-url-param yoo
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'parameters' => [
            [
                'description' => 'CSRF token',
                'in' => 'cookie',
                'name' => 'CSRF',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'in' => 'path',
                'name' => 'yoo',
                'schema' => [
                    'type' => 'string',
                ],
            ],
        ],
    ])]
    public function route15(): void {}


    /**
     * @return object{created_at: \DateTimeInterface}&\Traversable<int>
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'created_at' => [
                                    'format' => 'date-time',
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'created_at',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route16() {}


    /**
     * @return object{
     *     id: int,
     *     name?: string,
     * } & object{
     *     name: non-empty-string,
     *     uuid: string,
     * }
     *
     * @phpstan-ignore return.missing, return.unresolvableType
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => [
                                    'type' => 'integer',
                                ],
                                'name' => [
                                    'type' => 'string',
                                ],
                                'uuid' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'id',
                                'name',
                                'uuid',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route17() {}


    /**
     * @return array{
     *     id: int,
     *     name?: string,
     * } & array{
     *     name: non-empty-string,
     *     uuid: object{x?: int}|\Stringable,
     * }
     *
     * @phpstan-ignore return.missing, return.unresolvableType
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => [
                                    'type' => 'integer',
                                ],
                                'name' => [
                                    'type' => 'string',
                                ],
                                'uuid' => [
                                    'anyOf' => [
                                        [
                                            'properties' => [
                                                'x' => [
                                                    'type' => 'integer',
                                                ],
                                            ],
                                            'type' => 'object',
                                        ],
                                        [
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                            'required' => [
                                'id',
                                'name',
                                'uuid',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route18() {}


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'count' => [
                                    'type' => 'integer',
                                    'const' => 100,
                                ],
                                'name' => [
                                    'const' => 'yoo',
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'count',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route19(): mixed
    {
        return [
            'count' => 100,
            ...(rand(1, 2) > 1 ? ['name' => 'yoo'] : []),
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => [
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route20(): mixed
    {
        /** @var array{name?: string} */
        $arr = [];

        return [
            ...$arr,
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'anyOf' => [
                                    [
                                        'type' => 'array',
                                        'items' => [
                                            'enum' => [
                                                1,
                                                4,
                                            ],
                                            'type' => 'integer',
                                        ],
                                    ],
                                    [
                                        'type' => 'number',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route21(): mixed
    {
        $pi = 3.14;

        return [
            [...[1, 4]],
            ...[$pi, $pi + 1],
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function route22(): mixed
    {
        $a = new SimpleClass;
        $a = $a->getValue();

        return $a;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'message' => [
                                            'type' => 'string',
                                        ],
                                        'name' => [
                                            'const' => 'not_found',
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'name',
                                        'message',
                                    ],
                                ],
                                [
                                    'const' => 'ok',
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route23(): mixed
    {
        if (rand(0, 1)) {
            throw new NotFoundException;
        }

        return 'ok';
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
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route24(): mixed
    {
        return $this->methodFromTrait();
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
                                'const' => 1,
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route25(): mixed
    {
        $a = 1;

        $closure = function () use ($a) {
            return [
                $a,
            ];
        };

        return $closure();
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'number' => [
                                    'type' => 'number',
                                    'format' => 'float',
                                    'const' => 42.1,
                                ],
                            ],
                            'required' => [
                                'number',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route26(): mixed
    {
        $x = 42.1;

        $closure = function ($number) {
            return (object) [
                'number' => $number,
            ];
        };

        return $closure($x);
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'const' => 2,
                                    'type' => 'integer',
                                ],
                                [
                                    'const' => '%',
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route27(): mixed
    {
        $arrowFn = fn ($data) => $data['x'] > 5 ? $data['y'] : $data['z'];

        return $arrowFn([
            'x' => 1,
            'y' => 2,
            'z' => '%',
        ]);
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
                                    'properties' => [
                                        'a' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                    'required' => [
                                        'a',
                                    ],
                                    'type' => 'object',
                                ],
                                'b' => [
                                    'properties' => [
                                        'b' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                    'required' => [
                                        'b',
                                    ],
                                    'type' => 'object',
                                ],
                                'c' => [
                                    'const' => 15,
                                    'type' => 'integer',
                                ],
                                'd' => [
                                    'type' => 'boolean',
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
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route28(): mixed
    {
        $outerVar = true;
        $arrowFn = fn ($key) => [$key => $outerVar];

        return [
            'a' => $arrowFn('a'),
            'b' => $arrowFn('b'),
            'c' => (function () {
                $outerVar = 15;

                return $outerVar;
            })(),
            'd' => (function () use ($outerVar) {
                return $outerVar;
            })(),
        ];
    }


    /** @phpstan-ignore missingType.generics */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'enum' => [
                                    1,
                                    2,
                                ],
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route29(): ClassThatRepresentsAssocArray
    {
        return new ClassThatRepresentsAssocArray([
            'a' => 1,
            'b' => 2,
        ]);
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
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
                                            'const' => 100,
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'a',
                                        'b',
                                        'c',
                                    ],
                                ],
                                [
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
                                        'd' => [
                                            'const' => 100,
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'a',
                                        'b',
                                        'd',
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
    public function route30(): mixed
    {
        $cOrD = rand(1, 0) ? 'c' : 'd';

        return [
            'a' => 1,
            'b' => 2,
            $cOrD => 100,
        ];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'a' => [
                                            'const' => 5,
                                            'type' => 'integer',
                                        ],
                                        'c' => [
                                            'const' => 100,
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'a' => [
                                            'const' => 5,
                                            'type' => 'integer',
                                        ],
                                        'd' => [
                                            'const' => 100,
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'b' => [
                                            'const' => 5,
                                            'type' => 'integer',
                                        ],
                                        'c' => [
                                            'const' => 100,
                                            'type' => 'integer',
                                        ],
                                    ],
                                ],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'b' => [
                                            'const' => 5,
                                            'type' => 'integer',
                                        ],
                                        'd' => [
                                            'const' => 100,
                                            'type' => 'integer',
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
    public function route31(): mixed
    {
        $aOrB = rand(1, 0) ? 'a' : 'b';
        $cOrD = rand(1, 0) ? 'c' : 'd';

        return [
            $aOrB => 5,
            $cOrD => 100,
        ];
    }


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
                                15,
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route32(): mixed
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
    public function route33(): bool
    {
        return true;
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
    public function route34(): mixed
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
    public function route35(): mixed
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
    public function route36(): mixed
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
    public function route37(): mixed
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
    public function route38(): mixed
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
    public function route39(): mixed
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
                            'type' => 'array',
                            'items' => [
                                'properties' => [
                                    'category' => [
                                        'description' => '[RocketCategory](#/schemas/RocketCategory)',
                                        'enum' => [
                                            'Big',
                                            'Small',
                                        ],
                                        'type' => 'string',
                                    ],
                                    'id' => [
                                        'type' => 'integer',
                                    ],
                                    'is_flying' => [
                                        'type' => 'boolean',
                                    ],
                                    'launch_date' => [
                                        'format' => 'date-time',
                                        'type' => [
                                            'string',
                                            'null',
                                        ],
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => [
                                    'id',
                                    'name',
                                    'category',
                                    'launch_date',
                                    'is_flying',
                                ],
                                'type' => 'object',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route40(): mixed
    {
        return $this->methodFromTraitThatReturnsRockets();
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
                                    'type' => 'object',
                                    'properties' => [
                                        'category' => [
                                            'description' => '[RocketCategory](#/schemas/RocketCategory)',
                                            'enum' => [
                                                'Big',
                                                'Small',
                                            ],
                                            'type' => 'string',
                                        ],
                                        'id' => [
                                            'type' => 'integer',
                                        ],
                                        'is_flying' => [
                                            'type' => 'boolean',
                                        ],
                                        'launch_date' => [
                                            'format' => 'date-time',
                                            'type' => [
                                                'string',
                                                'null',
                                            ],
                                        ],
                                        'name' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'id',
                                        'name',
                                        'category',
                                        'launch_date',
                                        'is_flying',
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
    public function route41(): mixed
    {
        return $this->methodFromTraitThatReturnsArraysOfRocketsWithPhpDocOnly();
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
                                0,
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route42(): mixed
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
    public function route43(): mixed
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
    public function route44(): mixed
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


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'properties' => [
                                        'str' => [
                                            'const' => 'test',
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'str',
                                    ],
                                    'type' => 'object',
                                ],
                                [
                                    'type' => 'integer',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route45(): mixed
    {
        $var = $this->route45();

        if ($var) {
            /** @phpstan-ignore cast.int */
            $var = (int) $var;

            return $var;

        } else {
            /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
            $var['str'] = 'test';
        }

        return $var;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'items' => [
                                'anyOf' => [
                                    [
                                        'enum' => [
                                            'x',
                                            'y',
                                            'z',
                                        ],
                                        'type' => 'string',
                                    ],
                                    [
                                        'type' => 'integer',
                                    ],
                                ],
                            ],
                            'type' => 'array',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route46(): mixed
    {
        $var = rand(0, 500);

        if ($var > 300) {
            $var = 'x';

        } else if ($var > 200) {
            $var = 'y';

        } else if ($var > 100) {
            $var = 'z';
        }

        return [$var];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'const' => 200,
                                    'type' => 'integer',
                                ],
                                [
                                    'const' => 'A',
                                    'type' => 'string',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route47(): mixed
    {
        if (rand(0, 1)) {
            $a = 100;
            $a = 200;

            return $a;
        }

        $a = 'A';

        return $a;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'null',
                                    ],
                                ],
                                'x' => [
                                    'const' => 1,
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'data',
                                'x',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route48(): mixed
    {
        if (rand(0, 1)) {
            $obj = $this->getGenericClassInstance(GenericClass::class, [null]);

        } else {
            $obj = 0;
        }

        /** @phpstan-ignore offsetAccess.nonOffsetAccessible */
        $obj['x'] = 1;

        return $obj;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'const' => 50,
                            'type' => 'integer',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route49(): mixed
    {
        $a = 'initial';
        $f = fn () => $a;
        $a = 50;

        return $f();
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'const' => 50,
                                    'type' => 'integer',
                                ],
                                [
                                    'type' => 'boolean',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route50(): mixed
    {
        $a = 'initial';
        $f = fn () => $a;

        if (rand(0, 1)) {
            $a = 50;

            return $f();

        } else {
            $a = false;

            return $f();
        }
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'enum' => [
                                    1,
                                    2,
                                    3,
                                ],
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route51(): mixed
    {
        $array = [1, 2, 3];

        if (rand(0, 1)) {
            $a = [];

            while (count($a) < 10) {
                $a[] = $array[rand(1, 3)];
            }
        }

        /** @phpstan-ignore variable.undefined */
        return $a;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'nested' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'integer',
                                    ],
                                ],
                            ],
                            'required' => [
                                'nested',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function route52(): mixed
    {
        $array = [];

        $array['nested'][] = intval(1);

        return $array;
    }


    /**
     * @template TClass of object
     *
     * @param class-string<TClass> $className
     *
     * @return TClass
     */
    private function getClassInstance(string $className): object
    {
        return new $className;
    }

    /**
     * @template TClass of GenericClass
     * @template TParam
     *
     * @param class-string<TClass> $className
     * @param TParam $classConstructorParam
     *
     * @return TClass<TParam>
     */
    private function getGenericClassInstance(string $className, mixed $classConstructorParam): object
    {
        /** @phpstan-ignore return.type */
        return (object) [];
    }

    private function getGenericClassInstanceWithoutPhpDoc(string $className, mixed $classConstructorParam): mixed
    {
        return new $className($classConstructorParam);
    }
}
