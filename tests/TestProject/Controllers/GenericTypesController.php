<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\ClassThatRepresentsAssocArray;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;

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
     * Key of and value of
     *
     * `value-of` over an enum yields its backing type, over an array its item type.
     *
     * @return array{
     *     shapeKeys: key-of<array{id: int, label: string}>,
     *     shapeValues: value-of<array{id: int, label: string}>,
     *     listKeys: key-of<list<string>>,
     *     listValues: value-of<list<string>>,
     *     mapKeys: key-of<array<string, int>>,
     *     mapValues: value-of<array<string, int>>,
     *     untypedKeys: key-of<array<string>>,
     *     enumValues: value-of<StateEnum>,
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Key of and value of',
        'description' => '`value-of` over an enum yields its backing type, over an array its item type.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'shapeKeys' => [
                                    'type' => 'string',
                                    'enum' => [
                                        'id',
                                        'label',
                                    ],
                                ],
                                'shapeValues' => [
                                    'type' => [
                                        'integer',
                                        'string',
                                    ],
                                ],
                                'listKeys' => [
                                    'type' => 'integer',
                                ],
                                'listValues' => [
                                    'type' => 'string',
                                ],
                                'mapKeys' => [
                                    'type' => 'string',
                                ],
                                'mapValues' => [
                                    'type' => 'integer',
                                ],
                                'untypedKeys' => [
                                    'type' => [
                                        'integer',
                                        'string',
                                    ],
                                ],
                                'enumValues' => [
                                    'type' => 'integer',
                                    'description' => '[StateEnum](#/schemas/StateEnum)',
                                    'enum' => [
                                        1,
                                        2,
                                    ],
                                ],
                            ],
                            'required' => [
                                'shapeKeys',
                                'shapeValues',
                                'listKeys',
                                'listValues',
                                'mapKeys',
                                'mapValues',
                                'untypedKeys',
                                'enumValues',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function keyOfAndValueOf(): array
    {
        return [
            'shapeKeys' => 'id',
            'shapeValues' => 1,
            'listKeys' => 0,
            'listValues' => 'first',
            'mapKeys' => 'id',
            'mapValues' => 1,
            'untypedKeys' => 'id',
            'enumValues' => StateEnum::One->value,
        ];
    }


    /**
     * Conditional types
     *
     * Nothing decides the condition here, so both branches are documented.
     *
     * @template TValue
     *
     * @param TValue $value
     *
     * @return array{
     *     templateSubject: (TValue is string ? int : bool),
     *     negatedSubject: (TValue is not string ? int : bool),
     *     thisSubject: ($this is GenericTypesController ? string : int),
     *     nullableBranch: (TValue is string ? int : null),
     *     parameterSubject: ($value is string ? int : bool),
     *     nested: array{value: (TValue is int ? string : bool)},
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Conditional types',
        'description' => 'Nothing decides the condition here, so both branches are documented.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'templateSubject' => [
                                    'type' => [
                                        'integer',
                                        'boolean',
                                    ],
                                ],
                                'negatedSubject' => [
                                    'type' => [
                                        'integer',
                                        'boolean',
                                    ],
                                ],
                                'thisSubject' => [
                                    'type' => [
                                        'string',
                                        'integer',
                                    ],
                                ],
                                'nullableBranch' => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                ],
                                'parameterSubject' => [
                                    'type' => [
                                        'integer',
                                        'boolean',
                                    ],
                                ],
                                'nested' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'value' => [
                                            'type' => [
                                                'string',
                                                'boolean',
                                            ],
                                        ],
                                    ],
                                    'required' => [
                                        'value',
                                    ],
                                ],
                            ],
                            'required' => [
                                'templateSubject',
                                'negatedSubject',
                                'thisSubject',
                                'nullableBranch',
                                'parameterSubject',
                                'nested',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function conditionalTypes(mixed $value): array
    {
        return [
            'templateSubject' => 1,
            'negatedSubject' => 1,
            'thisSubject' => 'value',
            'nullableBranch' => null,
            'parameterSubject' => 1,
            'nested' => ['value' => 'value'],
        ];
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
