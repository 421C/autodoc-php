<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Exceptions\NotFoundException;

/**
 * Tests for control flow: if/else, loops, branching, spread operator.
 */
class ControlFlowController
{
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
    public function conditionalSpreadOperator(): mixed
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
    public function spreadFromTypedVariable(): mixed
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
    public function nestedSpread(): mixed
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
    public function dynamicKeyFromTernary(): mixed
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
    public function multipleDynamicKeys(): mixed
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
    public function recursiveCallWithBranching(): mixed
    {
        $var = $this->recursiveCallWithBranching();

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
    public function variableModifiedInIfElseChain(): mixed
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
    public function variableReassignedInBranch(): mixed
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
    public function objectModifiedAfterBranch(): mixed
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
    public function whileLoopBuildingArray(): mixed
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
    public function nestedArrayPush(): mixed
    {
        $array = [];

        $array['nested'][] = intval(1);

        return $array;
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'first' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'first 1' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'string',
                                            ],
                                        ],
                                        'first 2' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'string',
                                            ],
                                        ],
                                    ],
                                    'required' => [
                                        'first 1',
                                        'first 2',
                                    ],
                                ],
                                'second' => [
                                    'type' => 'object',
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
                                ],
                            ],
                            'required' => [
                                'first',
                                'second',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function emptyArrayCopiedAndModified(): mixed
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

        $array['second']['second 2'][] = $array['second']['second 1'][0];

        return $array;
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
                                        'index' => [
                                            'type' => 'number',
                                        ],
                                    ],
                                    'required' => [
                                        'index',
                                    ],
                                ],
                                [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'index' => [
                                                'type' => 'number',
                                            ],
                                        ],
                                        'required' => [
                                            'index',
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
    public function forLoopWithEarlyReturn(): mixed
    {
        $array = [];

        for ($i = 0; $i < 10; $i++) {
            $array[] = (object) [
                'index' => $i,
            ];
        }

        foreach ($array as $item) {
            if ($item->index === 5) {
                return $item;
            }
        }

        return $array;
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
    public function nullCoalesceWithThrow(): mixed
    {
        /** @var ?int */
        $var = null;

        $var = $var ?? throw new NotFoundException;

        return [$var][0];
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'rule 1' => [
                                    'const' => 2,
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'rule 1',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function arrayKeyOverwritten(): mixed
    {
        $rules = [
            'rule 1' => 1,
        ];

        $rules['rule 1'] = 2;

        return $rules;
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
}
