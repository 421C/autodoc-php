<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;

class TestController
{
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
        'requestBody' => null,
        'parameters' => [],
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
        'requestBody' => null,
        'parameters' => [],
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
                                            'description' => '[StateEnum](#/schemas/StateEnum)',
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
    public function route2(): mixed
    {
        $var = [
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
        'requestBody' => null,
        'parameters' => [],
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
        'requestBody' => null,
        'parameters' => [],
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
        'requestBody' => null,
        'parameters' => [],
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
        'parameters' => [],
        'requestBody' => null,
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
        'parameters' => [],
        'requestBody' => null,
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
        'parameters' => [],
        'requestBody' => null,
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
        'summary' => '',
        'description' => '',
        'parameters' => [],
        'requestBody' => null,
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
        'parameters' => [],
        'requestBody' => null,
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
        'parameters' => [],
        'requestBody' => null,
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
        'parameters' => [],
        'requestBody' => null,
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
