<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;

/**
 * Tests for closures and arrow functions.
 */
class ClosuresController
{
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
    public function closureWithUseVariable(): mixed
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
    public function closureWithParameter(): mixed
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
    public function arrowFunctionWithConditional(): mixed
    {
        /** @phpstan-ignore-next-line */
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
    public function arrowFunctionWithOuterVariable(): mixed
    {
        $outerVar = true;

        /** @phpstan-ignore-next-line */
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
    public function arrowFunctionCapturesVariableAtCallTime(): mixed
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
    public function arrowFunctionWithBranching(): mixed
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
}
