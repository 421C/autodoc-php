<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;

/**
 * Tests for the `callable` type keyword and `callable(...): T` signatures.
 */
class CallableTypesController
{
    /**
     * @var callable(): int
     */
    public $intFactory;

    /**
     * @var callable(): array{id: int, name: string}
     */
    public $recordFactory;

    // @phpstan-ignore missingType.iterableValue
    public iterable $iterableItems;

    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'value' => [
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'value',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function invokesDocBlockCallableSignature(): mixed
    {
        return [
            'value' => ($this->intFactory)(),
        ];
    }


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
                                    'id' => [
                                        'type' => 'integer',
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                    ],
                                ],
                                'required' => [
                                    'id',
                                    'name',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function mapsWithDocBlockCallableSignature(): mixed
    {
        return array_map($this->recordFactory, [1, 2, 3]);
    }


    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'items' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                            'required' => [
                                'items',
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function resolvesIterableKeywordFromReflection(): mixed
    {
        return [
            'items' => $this->iterableItems,
        ];
    }
}
