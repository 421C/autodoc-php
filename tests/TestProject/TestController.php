<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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
    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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


    #[ExpectedOperationSchema([
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
        return new $className($classConstructorParam);
    }

    private function getGenericClassInstanceWithoutPhpDoc(string $className, mixed $classConstructorParam): mixed
    {
        return new $className($classConstructorParam);
    }
}
