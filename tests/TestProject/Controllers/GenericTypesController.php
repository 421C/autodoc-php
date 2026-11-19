<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\ClassThatRepresentsAssocArray;
use AutoDoc\Tests\TestProject\Entities\ClassWithTypeAliasDefinition;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericContainer;
use AutoDoc\Tests\TestProject\Entities\GenericContainerChild;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;
use Closure;
use LogicException;

/**
 * Tests for generic types, class-string, templates.
 *
 * @phpstan-import-type TestTypeAlias from ClassWithTypeAliasDefinition
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
                            'type' => 'array',
                            'prefixItems' => [
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
                            'minItems' => 2,
                            'maxItems' => 2,
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
     * Offset access
     *
     * A literal offset picks that key, any other offset yields every value.
     *
     * @return array{
     *     literalOffset: TestTypeAlias['a'],
     *     typeOffset: TestTypeAlias[key-of<TestTypeAlias>],
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Offset access',
        'description' => 'A literal offset picks that key, any other offset yields every value.',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'literalOffset' => [
                                    'type' => 'integer',
                                ],
                                'typeOffset' => [
                                    'type' => [
                                        'integer',
                                        'string',
                                    ],
                                ],
                            ],
                            'required' => [
                                'literalOffset',
                                'typeOffset',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function offsetAccess(): array
    {
        /** @phpstan-ignore return.type */
        return $this->opaquePayload();
    }


    /**
     * Nullable doc types
     *
     * @return array{
     *     stringList: ?list<string>,
     *     stringArray: ?string[],
     *     shape: ?array{id: int},
     *     literal: ?'draft',
     *     literals: 'draft'|'published'|null,
     *     state: ?StateEnum,
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Nullable doc types',
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'stringList' => [
                                    'type' => [
                                        'array',
                                        'null',
                                    ],
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'stringArray' => [
                                    'type' => [
                                        'array',
                                        'null',
                                    ],
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'shape' => [
                                    'type' => [
                                        'object',
                                        'null',
                                    ],
                                    'properties' => [
                                        'id' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => [
                                        'id',
                                    ],
                                ],
                                'literal' => [
                                    'type' => [
                                        'string',
                                        'null',
                                    ],
                                    'enum' => [
                                        'draft',
                                        null,
                                    ],
                                ],
                                'literals' => [
                                    'type' => [
                                        'string',
                                        'null',
                                    ],
                                    'enum' => [
                                        'draft',
                                        'published',
                                        null,
                                    ],
                                ],
                                'state' => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                    'description' => '[StateEnum](#/schemas/StateEnum)',
                                    'enum' => [
                                        1,
                                        2,
                                        null,
                                    ],
                                ],
                            ],
                            'required' => [
                                'stringList',
                                'stringArray',
                                'shape',
                                'literal',
                                'literals',
                                'state',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function nullableDocTypes(): array
    {
        /** @phpstan-ignore return.type */
        return $this->opaquePayload();
    }


    /**
     * @return array{
     *     independentEntries: array{0?: int, 1?: string},
     *     severalOptionalEntries: array{0: int, 1?: string, 2?: bool},
     *     trailingEntries: list{0: int, 1?: string, 2?: bool},
     *     singleTrailingEntry: array{0: int, 1?: string},
     *     optionalStringKey: array{0: int, label?: string},
     *     optionalStringKeyFirst: array{label?: string, 0: int},
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'independentEntries' => [
                                    'anyOf' => [
                                        [
                                            'type' => 'array',
                                            'prefixItems' => [
                                                ['type' => 'integer'],
                                                ['type' => 'string'],
                                            ],
                                            'maxItems' => 2,
                                        ],
                                        [
                                            'type' => 'object',
                                            'properties' => [
                                                '0' => ['type' => 'integer'],
                                                '1' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                                'severalOptionalEntries' => [
                                    'anyOf' => [
                                        [
                                            'type' => 'array',
                                            'prefixItems' => [
                                                ['type' => 'integer'],
                                                ['type' => 'string'],
                                                ['type' => 'boolean'],
                                            ],
                                            'minItems' => 1,
                                            'maxItems' => 3,
                                        ],
                                        [
                                            'type' => 'object',
                                            'properties' => [
                                                '0' => ['type' => 'integer'],
                                                '1' => ['type' => 'string'],
                                                '2' => ['type' => 'boolean'],
                                            ],
                                            'required' => ['0'],
                                        ],
                                    ],
                                ],
                                'trailingEntries' => [
                                    'type' => 'array',
                                    'prefixItems' => [
                                        ['type' => 'integer'],
                                        ['type' => 'string'],
                                        ['type' => 'boolean'],
                                    ],
                                    'minItems' => 1,
                                    'maxItems' => 3,
                                ],
                                'singleTrailingEntry' => [
                                    'type' => 'array',
                                    'prefixItems' => [
                                        ['type' => 'integer'],
                                        ['type' => 'string'],
                                    ],
                                    'minItems' => 1,
                                    'maxItems' => 2,
                                ],
                                'optionalStringKey' => [
                                    'anyOf' => [
                                        [
                                            'type' => 'array',
                                            'prefixItems' => [
                                                ['type' => 'integer'],
                                            ],
                                            'minItems' => 1,
                                            'maxItems' => 1,
                                        ],
                                        [
                                            'type' => 'object',
                                            'properties' => [
                                                '0' => ['type' => 'integer'],
                                                'label' => ['type' => 'string'],
                                            ],
                                            'required' => ['0'],
                                        ],
                                    ],
                                ],
                                'optionalStringKeyFirst' => [
                                    'anyOf' => [
                                        [
                                            'type' => 'array',
                                            'prefixItems' => [
                                                ['type' => 'integer'],
                                            ],
                                            'minItems' => 1,
                                            'maxItems' => 1,
                                        ],
                                        [
                                            'type' => 'object',
                                            'properties' => [
                                                'label' => ['type' => 'string'],
                                                '0' => ['type' => 'integer'],
                                            ],
                                            'required' => ['0'],
                                        ],
                                    ],
                                ],
                            ],
                            'required' => [
                                'independentEntries',
                                'severalOptionalEntries',
                                'trailingEntries',
                                'singleTrailingEntry',
                                'optionalStringKey',
                                'optionalStringKeyFirst',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayAndListShapeKinds(): array
    {
        /** @phpstan-ignore return.type */
        return $this->opaquePayload();
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
        /** @phpstan-ignore return.type */
        return $this->opaquePayload();
    }


    /**
     * Class template values in nested positions
     *
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Class template values in nested positions',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'container' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'nullable' => [
                                            'type' => ['integer', 'null'],
                                            'enum' => [1, null],
                                        ],
                                        'items' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'integer',
                                                'const' => 1,
                                            ],
                                        ],
                                        'wrapped' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'value' => [
                                                    'type' => 'integer',
                                                    'const' => 1,
                                                ],
                                            ],
                                            'required' => ['value'],
                                        ],
                                        'keyed' => [
                                            'type' => 'object',
                                            'additionalProperties' => [
                                                'type' => 'integer',
                                                'const' => 1,
                                            ],
                                        ],
                                    ],
                                    'required' => ['nullable', 'items', 'wrapped'],
                                ],
                                'child' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'nullable' => [
                                            'type' => ['string', 'null'],
                                            'enum' => ['x', null],
                                        ],
                                        'items' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'string',
                                                'const' => 'x',
                                            ],
                                        ],
                                        'wrapped' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'value' => [
                                                    'type' => 'string',
                                                    'const' => 'x',
                                                ],
                                            ],
                                            'required' => ['value'],
                                        ],
                                    ],
                                    'required' => ['nullable', 'items', 'wrapped'],
                                ],
                            ],
                            'required' => ['container', 'child'],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function classTemplateInNestedPositions(): array
    {
        return [
            'container' => new GenericContainer(1),
            'child' => new GenericContainerChild('x'),
        ];
    }


    /**
     * Template bindings from argument positions
     *
     * `valueOrClosure` binds nothing, since the argument could match either union member.
     *
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Template bindings from argument positions',
        'description' => '`valueOrClosure` binds nothing, since the argument could match either union member.',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'arrayKeys' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                        'enum' => ['a', 'b'],
                                    ],
                                ],
                                'nonNullItems' => [
                                    'type' => 'object',
                                    'additionalProperties' => [
                                        'type' => 'integer',
                                        'const' => 1,
                                    ],
                                ],
                                'nullable' => [
                                    'type' => 'integer',
                                    'const' => 5,
                                ],
                                'bracketArrayItem' => [
                                    'type' => 'integer',
                                    'enum' => [1, 2],
                                ],
                                'nestedListItem' => [
                                    'type' => 'integer',
                                    'enum' => [1, 2],
                                ],
                                'valueOrClosure' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => ['arrayKeys', 'nonNullItems', 'nullable', 'bracketArrayItem', 'nestedListItem', 'valueOrClosure'],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function templateBindingPositions(): array
    {
        return [
            'arrayKeys' => $this->templateArrayKeys(['a' => 1, 'b' => 2]),
            'nonNullItems' => $this->templateWithoutNullItems(['a' => 1, 'b' => null]),
            'nullable' => $this->templateNullable(rand(0, 1) ? 5 : null),
            'bracketArrayItem' => $this->templateBracketArrayItem([1, 2]),
            'nestedListItem' => $this->templateNestedListItem(['a' => [1, 2]]),
            'valueOrClosure' => $this->templateValueOrClosure(5),
        ];
    }


    /**
     * Template binding from a variable
     *
     * The variable keeps its shape after the call binds a template from it.
     *
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Template binding from a variable',
        'description' => 'The variable keeps its shape after the call binds a template from it.',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'keys' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                        'enum' => ['a', 'b'],
                                    ],
                                ],
                                'variableAfterCall' => [
                                    'type' => 'integer',
                                    'const' => 1,
                                ],
                            ],
                            'required' => ['keys', 'variableAfterCall'],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function templateBindingFromVariable(): array
    {
        $items = ['a' => 1, 'b' => 'x'];

        return [
            'keys' => $this->templateArrayKeys($items),
            'variableAfterCall' => $items['a'],
        ];
    }


    /**
     * Template bindings from callable arguments
     *
     * The callback is invoked with the declared parameter types, and a template named by two callbacks takes both return types.
     *
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Template bindings from callable arguments',
        'description' => 'The callback is invoked with the declared parameter types, and a template named by two callbacks takes both return types.',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'closure' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => [
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => ['name'],
                                ],
                                'arrowFunction' => [
                                    'type' => 'integer',
                                    'const' => 5,
                                ],
                                'firstClassCallable' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'length' => [
                                            'type' => 'integer',
                                        ],
                                    ],
                                    'required' => ['length'],
                                ],
                                'declaredParameterType' => [
                                    'type' => 'integer',
                                ],
                                'closureType' => [
                                    'type' => 'boolean',
                                ],
                                'nullableCallable' => [
                                    'type' => 'integer',
                                    'const' => 7,
                                ],
                                'twoCallbacks' => [
                                    'anyOf' => [
                                        [
                                            'type' => 'integer',
                                            'const' => 1,
                                        ],
                                        [
                                            'type' => 'string',
                                            'const' => 'x',
                                        ],
                                    ],
                                ],
                                'throwingCallback' => [
                                    'type' => 'integer',
                                ],
                                'forwarded' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'forwarded' => [
                                            'type' => 'integer',
                                            'const' => 1,
                                        ],
                                    ],
                                    'required' => ['forwarded'],
                                ],
                                'forwardedThroughClosure' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'wrapped' => [
                                            'type' => 'integer',
                                            'const' => 2,
                                        ],
                                    ],
                                    'required' => ['wrapped'],
                                ],
                            ],
                            'required' => [
                                'closure',
                                'arrowFunction',
                                'firstClassCallable',
                                'declaredParameterType',
                                'closureType',
                                'nullableCallable',
                                'twoCallbacks',
                                'throwingCallback',
                                'forwarded',
                                'forwardedThroughClosure',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function templateBindingFromCallables(): array
    {
        return [
            'closure' => $this->templateFromCallback(function (string $value) {
                return ['name' => $value];
            }),
            'arrowFunction' => $this->templateFromCallback(fn () => 5),
            'firstClassCallable' => $this->templateFromCallback($this->measureLength(...)),
            'declaredParameterType' => $this->templateFromIntCallback(fn ($number) => $number),
            'closureType' => $this->templateFromClosure(fn (int $number) => $number > 0),
            'nullableCallable' => $this->templateFromNullableCallback(fn () => 7),
            'twoCallbacks' => $this->templateFromEitherCallback(
                success: fn () => 1,
                error: fn () => 'x',
            ),
            'throwingCallback' => $this->templateFromBoundedCallback(fn () => throw new LogicException),
            'forwarded' => $this->forwardTemplateCallback(fn () => ['forwarded' => 1]),
            'forwardedThroughClosure' => $this->forwardTemplateCallbackThroughClosure(fn () => ['wrapped' => 2]),
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


    /**
     * An unknown analyzed type leaves a route's PHPDoc `@return` type in place.
     */
    private function opaquePayload(): mixed
    {
        return json_decode('null', true);
    }


    /**
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $items
     *
     * @return list<TKey>
     */
    private function templateArrayKeys(array $items): array
    {
        return array_keys($items);
    }


    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue|null> $items
     *
     * @return array<TKey, TValue>
     */
    private function templateWithoutNullItems(array $items): array
    {
        return array_filter($items, fn ($item) => $item !== null);
    }


    /**
     * @template T
     *
     * @param ?T $value
     *
     * @return T
     */
    private function templateNullable(mixed $value): mixed
    {
        return $value ?? throw new LogicException;
    }


    /**
     * @template T
     *
     * @param T[] $items
     *
     * @return T
     */
    private function templateBracketArrayItem(array $items): mixed
    {
        foreach ($items as $item) {
            return $item;
        }

        throw new LogicException;
    }


    /**
     * @template K of array-key
     * @template V
     *
     * @param array<K, list<V>> $groups
     *
     * @return V
     */
    private function templateNestedListItem(array $groups): mixed
    {
        foreach ($groups as $group) {
            foreach ($group as $item) {
                return $item;
            }
        }

        throw new LogicException;
    }


    /**
     * @template T
     *
     * @param T|(Closure(): T) $value
     *
     * @return T
     */
    private function templateValueOrClosure(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }


    /**
     * @template TResult
     *
     * @param callable(string): TResult $callback
     *
     * @return TResult
     */
    private function templateFromCallback(callable $callback): mixed
    {
        return $callback('x');
    }


    /**
     * @template TResult
     *
     * @param callable(int): TResult $callback
     *
     * @return TResult
     */
    private function templateFromIntCallback(callable $callback): mixed
    {
        return $callback(1);
    }


    /**
     * @template TResult
     *
     * @param Closure(int): TResult $callback
     *
     * @return TResult
     */
    private function templateFromClosure(Closure $callback): mixed
    {
        return $callback(1);
    }


    /**
     * @template TResult
     *
     * @param null|callable(): TResult $callback
     *
     * @return TResult
     */
    private function templateFromNullableCallback(?callable $callback): mixed
    {
        return $callback ? $callback() : throw new LogicException;
    }


    /**
     * @template TResult
     *
     * @param callable(): TResult $success
     * @param callable(): TResult $error
     *
     * @return TResult
     */
    private function templateFromEitherCallback(callable $success, callable $error): mixed
    {
        return rand(0, 1) ? $success() : $error();
    }


    /**
     * @template TResult of int
     *
     * @param callable(): TResult $callback
     *
     * @return TResult
     */
    private function templateFromBoundedCallback(callable $callback): mixed
    {
        return $callback();
    }


    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     */
    private function forwardTemplateCallback(callable $callback): mixed
    {
        return $this->templateFromNullableCallback($callback);
    }


    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     */
    private function forwardTemplateCallbackThroughClosure(callable $callback): mixed
    {
        return $this->templateFromNullableCallback(fn () => $callback());
    }


    /**
     * @return array{length: int}
     */
    private function measureLength(string $value): array
    {
        return ['length' => strlen($value)];
    }
}
