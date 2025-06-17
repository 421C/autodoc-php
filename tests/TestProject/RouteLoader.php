<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject;

use AutoDoc\AbstractRouteLoader;
use AutoDoc\Route;
use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use ReflectionClass;
use ReflectionMethod;

class RouteLoader extends AbstractRouteLoader
{
    public function getRoutes(): iterable
    {
        $testController = new ReflectionClass(TestController::class);

        $publicMethods = $testController->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            yield new Route(
                uri: '/api/test/' . $method->getName(),
                method: 'get',
                className: TestController::class,
                classMethod: $method->getName(),
            );
        }

        /**
         * Closure test 1
         *
         * Description...
         *
         * @return array<object{test: bool}>
         */
        $closure1 = #[ExpectedOperationSchema([
            'summary' => 'Closure test 1',
            'description' => 'Description...',
            'responses' => [
                200 => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'test' => [
                                            'type' => 'boolean',
                                        ],
                                    ],
                                    'required' => [
                                        'test',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'description' => '',
                ],
            ],
        ])] function () {};


        $closure2 = #[ExpectedOperationSchema([
            'responses' => [
                200 => [
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                            ],
                        ],
                    ],
                    'description' => '',
                ],
            ],
        ])] function (): object { return (object) []; };


        yield new Route(
            uri: '/api/test/closure1',
            method: 'get',
            closure: $closure1,
        );

        yield new Route(
            uri: '/api/test/closure2',
            method: 'get',
            closure: $closure2,
        );

        yield new Route(
            uri: '/api/test/closure3',
            method: 'get',
            closure: (
                /**
                 * @return int|float|null
                 */
                #[ExpectedOperationSchema([
                    'summary' => '',
                    'description' => '',
                    'responses' => [
                        200 => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => [
                                            'number',
                                            'null',
                                        ],
                                    ],
                                ],
                            ],
                            'description' => '',
                        ],
                    ],
                ])] function () {}
            ),
        );

        yield new Route(
            uri: '/api/test/closure4',
            method: 'get',
            closure: (
                /**
                 * @return array{
                 *     list: array<array{id: int, name: string}>,
                 * }
                 */
                #[ExpectedOperationSchema([
                    'summary' => '',
                    'description' => '',
                    'responses' => [
                        200 => [
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'list' => [
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
                                        'required' => [
                                            'list',
                                        ],
                                    ],
                                ],
                            ],
                            'description' => '',
                        ],
                    ],
                ])] function () {}
            ),
        );
    }
}
