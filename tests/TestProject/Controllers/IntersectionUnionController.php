<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;

/**
 * Tests for intersection and union types.
 */
class IntersectionUnionController
{
    /**
     * @return object{created_at: \DateTimeInterface}&\Traversable<int>
     * @phpstan-ignore return.missing
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'properties' => [
                                'created_at' => [
                                    'format' => 'date-time',
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'created_at',
                            ],
                            'type' => 'object',
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function objectWithDateTimeIntersection() {}


    /**
     * @return object{
     *     id: int,
     *     name?: string,
     * } & object{
     *     name: non-empty-string,
     *     uuid: string,
     * }
     *
     * @phpstan-ignore return.missing, return.unresolvableType
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => [
                                    'type' => 'integer',
                                ],
                                'name' => [
                                    'type' => 'string',
                                ],
                                'uuid' => [
                                    'type' => 'string',
                                ],
                            ],
                            'required' => [
                                'id',
                                'name',
                                'uuid',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function objectIntersectionMergesProperties() {}


    /**
     * @return array{
     *     id: int,
     *     name?: string,
     * } & array{
     *     name: non-empty-string,
     *     uuid: object{x?: int}|\Stringable,
     * }
     *
     * @phpstan-ignore return.missing, return.unresolvableType
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'responses' => [
            200 => [
                'description' => '',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => [
                                    'type' => 'integer',
                                ],
                                'name' => [
                                    'type' => 'string',
                                ],
                                'uuid' => [
                                    'anyOf' => [
                                        [
                                            'properties' => [
                                                'x' => [
                                                    'type' => 'integer',
                                                ],
                                            ],
                                            'type' => 'object',
                                        ],
                                        [
                                            'type' => 'string',
                                        ],
                                    ],
                                ],
                            ],
                            'required' => [
                                'id',
                                'name',
                                'uuid',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])]
    public function intersectionWithUnionProperty() {}
}
