<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\TextProcessor;

/**
 * Tests for PHP 8.5 pipe operator (|>) functionality.
 */
class PipeOperationController
{
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
    public function pipeWithBuiltInFunction(): mixed
    {
        return '' |> 'boolval';
    }

    /**
     * Pipe with arrow function.
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'Pipe with arrow function.',
        'description' => '',
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'number',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function pipeWithArrowFunction(): mixed
    {
        return 5 |> (fn ($x) => $x * 2);
    }

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function pipeWithClosure(): mixed
    {
        return 'test' |> function ($s) { return strlen($s); };
    }

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function chainedPipeOperations(): mixed
    {
        return 'hello' |> 'strtoupper' |> 'strlen';
    }

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'integer',
                            'const' => 145,
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function pipeWithStaticMethod(): mixed
    {
        return 145 |> GenericClass::from(...) |> (fn ($enum) => $enum->data);
    }

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'description' => '',
        'summary' => '',
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => [
                                    'const' => 'test',
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'text',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function pipeWithInstanceMethod(): array
    {
        return 'test' |> new TextProcessor()->process(...);
    }

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'text/plain' => [
                        'schema' => [
                            'type' => 'integer',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function pipeWithFirstClassCallable(): int
    {
        return 'hello' |> strlen(...);
    }
}
