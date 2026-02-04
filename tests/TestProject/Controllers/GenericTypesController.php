<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\ClassThatRepresentsAssocArray;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;

/**
 * Tests for generic types, class-string, templates.
 */
class GenericTypesController
{
    /**
     * Class from class-string
     *
     * @phpstan-ignore missingType.return
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Class from class-string',
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
    public function classFromClassString()
    {
        $classString = SimpleClass::class;

        return $this->getClassInstance($classString);
    }


    /**
     * Generic class with null param
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Generic class with null param',
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
    public function genericClassWithNullParam(): object
    {
        return $this->getGenericClassInstance(GenericClass::class, null);
    }


    /**
     * Array of generic subclasses
     *
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Array of generic subclasses',
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
                                'required' => [
                                    'n',
                                    'data',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayOfGenericSubclasses(): array
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
    public function genericClassWithMixedArrayParam()
    {
        return new GenericClass([
            'abc',
            123,
        ]);
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
                                '0',
                                '1',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function tupleWithTemplateType(mixed $value): object
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
    public function classFromParamDocblock($value): object
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
    public function classFromTypehint(SimpleClass $value): object
    {
        return $value;
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
    public function classRepresentingAssocArray(): ClassThatRepresentsAssocArray
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
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function classMethodReturnValue(): mixed
    {
        $a = new SimpleClass;
        $a = $a->getValue();

        return $a;
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
