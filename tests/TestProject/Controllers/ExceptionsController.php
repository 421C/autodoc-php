<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Exceptions\NotFoundException;

/**
 * Tests for exception handling in response schemas.
 */
class ExceptionsController
{
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
                                    'const' => 'ok',
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
    public function throwsNotFoundException(): mixed
    {
        if (rand(0, 1)) {
            throw new NotFoundException;
        }

        return 'ok';
    }
}
