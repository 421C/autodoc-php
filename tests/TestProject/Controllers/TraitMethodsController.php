<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Traits\TestTrait;

/**
 * Tests for trait method return types.
 */
class TraitMethodsController
{
    use TestTrait;

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
    public function traitMethodWithTypeAlias(): mixed
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
                                        'deprecated' => true,
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
    public function traitMethodReturnsRockets(): mixed
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
                                            'deprecated' => true,
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
    public function traitMethodReturnsNestedRockets(): mixed
    {
        return $this->methodFromTraitThatReturnsArraysOfRocketsWithPhpDocOnly();
    }
}
