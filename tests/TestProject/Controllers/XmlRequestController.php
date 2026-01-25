<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Controllers;

use AutoDoc\Tests\Attributes\ExpectedOperationSchema;

class XmlRequestController
{
    /**
     * An endpoint that accepts XML in request body.
     *
     * Description of the endpoint.
     *
     * @request-body string
     * @request-header Content-Type {type: 'application/xml'}
     * @phpstan-ignore missingType.iterableValue
     */
    #[ExpectedOperationSchema('showValuesForScalarTypes', [
        'summary' => 'An endpoint that accepts XML in request body.',
        'description' => 'Description of the endpoint.',
        'parameters' => [
            [
                'in' => 'header',
                'name' => 'Content-Type',
                'schema' => [
                    'const' => 'application/xml',
                    'type' => 'string',
                ],
            ],
        ],
        'requestBody' => [
            'content' => [
                'application/xml' => [
                    'schema' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'description' => '',
            'required' => false,
        ],
        'responses' => [
            200 => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'anyOf' => [
                                [
                                    'properties' => [
                                        'error' => [
                                            'const' => 'Invalid XML',
                                            'type' => 'string',
                                        ],
                                    ],
                                    'required' => [
                                        'error',
                                    ],
                                    'type' => 'object',
                                ],
                                [
                                    'items' => [
                                        'properties' => [
                                            'amount' => [
                                                'format' => 'float',
                                                'type' => 'number',
                                            ],
                                            'customer' => [
                                                'type' => 'string',
                                            ],
                                            'points' => [
                                                'enum' => [
                                                    10,
                                                    1,
                                                ],
                                                'type' => 'integer',
                                            ],
                                        ],
                                        'required' => [
                                            'customer',
                                            'amount',
                                            'points',
                                        ],
                                        'type' => 'object',
                                    ],
                                    'type' => 'array',
                                ],
                            ],
                        ],
                    ],
                ],
                'description' => '',
            ],
        ],
    ])]
    public function process(): array
    {
        $xml = simplexml_load_string(file_get_contents('php://input')); // @phpstan-ignore argument.type

        if (! $xml) {
            return ['error' => 'Invalid XML'];
        }

        return array_map(function ($order) {
            $amount = (float) $order->amount;

            return [
                'customer' => $order->customer,
                'amount' => $amount,
                'points' => $amount > 1000 ? 10 : 1,
            ];
        }, (array) $xml->order);
    }
}
