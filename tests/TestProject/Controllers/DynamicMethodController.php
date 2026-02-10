<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\ClassWithDynamicMethods;

class DynamicMethodController
{
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                0 => [
                                    'type' => [
                                        'integer',
                                        'null',
                                    ],
                                ],
                                1 => [
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
                                    'type' => 'array',
                                ],
                                'state' => [
                                    'description' => '[StateEnum](#/schemas/StateEnum)',
                                    'enum' => [
                                        1,
                                        2,
                                    ],
                                    'type' => 'integer',
                                ],
                            ],
                            'required' => [
                                'state',
                                '0',
                                '1',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function dynamicInstanceMethods(): mixed
    {
        $obj = new ClassWithDynamicMethods;

        return [
            'state' => $obj->getState(),
            0 => $obj->getStringOrInt(),
            1 => $obj->getRockets(),
        ];
    }
}
