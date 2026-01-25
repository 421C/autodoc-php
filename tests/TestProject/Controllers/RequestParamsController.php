<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Entities\StateEnum;

/**
 * Tests for request parameters: query, headers, cookies, URL params, body.
 */
class RequestParamsController
{
    /**
     * @request-query filter {type: string[]}
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'parameters' => [
            [
                'in' => 'query',
                'name' => 'filter',
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ],
    ])]
    public function queryParamArrayOfStrings(): void {}


    /**
     * @request-header Authorization {required: true, description: 'Authorization header'}
     * @request-header x-state {description: 'Status', deprecated: true, type: StateEnum}
     *
     * @request object{
     *     data: array<string, array{
     *         id: int,
     *         name?: string,
     *     }>
     * }
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'parameters' => [
            [
                'in' => 'header',
                'name' => 'Authorization',
                'description' => 'Authorization header',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'in' => 'header',
                'name' => 'x-state',
                'description' => 'Status',
                'deprecated' => true,
                'schema' => [
                    'description' => '[StateEnum](#/schemas/StateEnum)',
                    'enum' => [
                        1,
                        2,
                    ],
                    'type' => 'integer',
                ],
            ],
        ],
        'requestBody' => [
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'additionalProperties' => [
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
            'required' => false,
        ],
    ])]
    public function headersAndRequestBody(): void {}


    /**
     * @request-cookie CSRF {description: 'CSRF token'}
     * @request-url-param yoo
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => '',
        'description' => '',
        'parameters' => [
            [
                'description' => 'CSRF token',
                'in' => 'cookie',
                'name' => 'CSRF',
                'schema' => [
                    'type' => 'string',
                ],
            ],
            [
                'in' => 'path',
                'name' => 'yoo',
                'schema' => [
                    'type' => 'string',
                ],
            ],
        ],
    ])]
    public function cookieAndUrlParam(): void {}
}
