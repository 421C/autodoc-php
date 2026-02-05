<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject;

use AutoDoc\AbstractRouteLoader;
use AutoDoc\Route;
use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Controllers\ArrayOperationsController;
use AutoDoc\Tests\TestProject\Controllers\BasicResponsesController;
use AutoDoc\Tests\TestProject\Controllers\ClosuresController;
use AutoDoc\Tests\TestProject\Controllers\ControlFlowController;
use AutoDoc\Tests\TestProject\Controllers\DynamicKeysController;
use AutoDoc\Tests\TestProject\Controllers\DynamicMethodController;
use AutoDoc\Tests\TestProject\Controllers\ExceptionsController;
use AutoDoc\Tests\TestProject\Controllers\GenericTypesController;
use AutoDoc\Tests\TestProject\Controllers\IntersectionUnionController;
use AutoDoc\Tests\TestProject\Controllers\RequestParamsController;
use AutoDoc\Tests\TestProject\Controllers\TraitMethodsController;
use AutoDoc\Tests\TestProject\Controllers\XmlRequestController;
use ReflectionClass;
use ReflectionMethod;

class RouteLoader extends AbstractRouteLoader
{
    /**
     * @var array<class-string>
     */
    private array $controllers = [
        BasicResponsesController::class,
        GenericTypesController::class,
        IntersectionUnionController::class,
        RequestParamsController::class,
        ClosuresController::class,
        ArrayOperationsController::class,
        ControlFlowController::class,
        ExceptionsController::class,
        TraitMethodsController::class,
        DynamicKeysController::class,
        DynamicMethodController::class,
        XmlRequestController::class,
    ];

    public function getRoutes(): iterable
    {
        foreach ($this->controllers as $controllerClass) {
            $controller = new ReflectionClass($controllerClass);
            $publicMethods = $controller->getMethods(ReflectionMethod::IS_PUBLIC);

            $shortName = $controller->getShortName();
            $prefix = strtolower(preg_replace('/Controller$/', '', $shortName) ?? $shortName);

            foreach ($publicMethods as $method) {
                if ($method->class !== $controllerClass) {
                    continue;
                }

                yield new Route(
                    uri: '/api/test/' . $prefix . '/' . $this->toKebabCase($method->getName()),
                    method: 'post',
                    className: $controllerClass,
                    classMethod: $method->getName(),
                );
            }
        }

        yield from $this->getClosureRoutes();
    }

    private function toKebabCase(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $input) ?? $input);
    }

    /**
     * @return iterable<Route>
     */
    private function getClosureRoutes(): iterable
    {
        /**
         * Closure test 1
         *
         * Description...
         *
         * @return array<object{test: bool}>
         */
        $closure1 = #[ExpectedOperationSchema('showValuesForScalarTypes', [
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


        $closure2 = #[ExpectedOperationSchema('showValuesForScalarTypes', [
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
                #[ExpectedOperationSchema('showValuesForScalarTypes', [
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
                #[ExpectedOperationSchema('showValuesForScalarTypes', [
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
