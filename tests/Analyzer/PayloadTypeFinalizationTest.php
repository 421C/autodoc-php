<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\PayloadTypeFinalizer;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnresolvedType;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\RequestBody;
use AutoDoc\OpenApi\Response;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\Entities\DeepNullableNestedPropertyRoot;
use AutoDoc\Tests\TestProject\Entities\NullableNestedPropertyRoot;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Extensions\EachCallbackExtension;
use AutoDoc\Tests\TestProject\Extensions\SideEffectMethodExtension;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class PayloadTypeFinalizationTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    protected function setUp(): void
    {
        PhpClass::$cache = [];
        SideEffectMethodExtension::$dispatchLog = [];
        CloneCountingType::$cloneCount = 0;
    }

    #[Test]
    public function conditionalMutationOfNullableNestedObjectIsAnOptionalPropertyInTheResponse(): void
    {
        $schema = $this->analyze(function (NullableNestedPropertyRoot $model, bool $flag): mixed {
            if ($flag) {
                // @phpstan-ignore method.notFound
                $model = $model->eachItem(function (NullableNestedPropertyRoot $item): void {
                    // @phpstan-ignore method.notFound
                    $item->b?->injectNested();
                });
            }

            return $model;
        });

        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'object',
                'properties' => [
                    'b' => [
                        'type' => [
                            'object',
                            'null',
                        ],
                        'properties' => [
                            'c' => [
                                'type' => [
                                    'integer',
                                    'null',
                                ],
                            ],
                            'tagged' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => [
                            'c',
                        ],
                    ],
                ],
                'required' => [
                    'b',
                ],
            ],
            actual: $schema,
            uri: '/test',
            method: 'response',
        );
    }

    #[Test]
    public function finalizingResponseDoesNotLeakMutationIntoCachedClass(): void
    {
        $this->analyze(function (NullableNestedPropertyRoot $model, bool $flag): mixed {
            if ($flag) {
                // @phpstan-ignore method.notFound
                $model = $model->eachItem(function (NullableNestedPropertyRoot $item): void {
                    // @phpstan-ignore method.notFound
                    $item->b?->injectNested();
                });
            }

            return $model;
        });

        $schema = $this->analyze(
            fn (NullableNestedPropertyRoot $model): mixed => $model,
        );

        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'object',
                'properties' => [
                    'b' => [
                        'type' => [
                            'object',
                            'null',
                        ],
                        'properties' => [
                            'c' => [
                                'type' => [
                                    'integer',
                                    'null',
                                ],
                            ],
                        ],
                        'required' => [
                            'c',
                        ],
                    ],
                ],
                'required' => [
                    'b',
                ],
            ],
            actual: $schema,
            uri: '/test/plain',
            method: 'response',
        );
    }

    #[Test]
    public function finalizingResponseDoesNotLeakDeepMutationIntoCachedClass(): void
    {
        $this->analyze(function (DeepNullableNestedPropertyRoot $model, bool $flag): mixed {
            if ($flag) {
                // @phpstan-ignore method.notFound
                $model = $model->eachItem(function (DeepNullableNestedPropertyRoot $item): void {
                    // @phpstan-ignore method.notFound
                    $item->branch?->leaf->injectNested();
                });
            }

            return $model;
        });

        $schema = $this->analyze(
            fn (DeepNullableNestedPropertyRoot $model): mixed => $model,
        );

        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'object',
                'properties' => [
                    'branch' => [
                        'type' => [
                            'object',
                            'null',
                        ],
                        'properties' => [
                            'leaf' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => [
                                        'type' => [
                                            'integer',
                                            'null',
                                        ],
                                    ],
                                ],
                                'required' => [
                                    'value',
                                ],
                            ],
                        ],
                        'required' => [
                            'leaf',
                        ],
                    ],
                ],
                'required' => [
                    'branch',
                ],
            ],
            actual: $schema,
            uri: '/test/plain-deep',
            method: 'response',
        );
    }

    #[Test]
    public function unresolvedPropertyOwnsRequirednessOverItsResolvedType(): void
    {
        $unresolvedProperty = new class extends UnresolvedType
        {
            public function resolve(): Type
            {
                return (new StringType)->setRequired(true);
            }
        };
        $finalizedType = $this->finalizer()->finalizeResponseTypes(
            types: [new ObjectType(properties: ['value' => $unresolvedProperty])],
        );

        $this->assertInstanceOf(ObjectType::class, $finalizedType);
        $this->assertFalse($finalizedType->properties['value']->required);
    }

    #[Test]
    public function collapsedResponseUnionKeepsMemberRequiredness(): void
    {
        $finalizedType = $this->finalizer()->finalizeResponseTypes(
            types: [(new StringType)->setRequired(true)],
        );

        $this->assertInstanceOf(StringType::class, $finalizedType);
        $this->assertTrue($finalizedType->required);
    }

    #[Test]
    public function collapsedUnionContainingResolverKeepsResolvedMemberRequiredness(): void
    {
        $finalizedType = $this->finalizer()->finalizeResponseTypes(
            types: [new SharedTypeResolver((new StringType)->setRequired(true))],
        );

        $this->assertInstanceOf(StringType::class, $finalizedType);
        $this->assertTrue($finalizedType->required);
    }

    #[Test]
    public function finalizationDeepClonesEachInputMemberOnce(): void
    {
        $this->finalizer()->finalizeResponseTypes(
            types: [new ObjectType(properties: ['value' => new CloneCountingType])],
        );

        $this->assertSame(1, CloneCountingType::$cloneCount);
    }

    #[Test]
    public function collapsedUnionClonesTypeReturnedByNestedResolver(): void
    {
        $sharedProperty = new StringType;
        $sharedType = new ObjectType(properties: ['value' => $sharedProperty]);
        $finalizedType = $this->finalizer()->finalizeResponseTypes(
            types: [new SharedTypeResolver($sharedType)],
        );

        $this->assertInstanceOf(ObjectType::class, $finalizedType);
        $finalizedType->properties['value']->setRequired(true);
        $this->assertFalse($sharedProperty->required);
    }

    #[Test]
    public function rootSameClassAlternativesRemainDistinct(): void
    {
        $finalizedType = $this->finalizer()->finalizeResponseTypes(types: [
            new ObjectType(
                properties: ['left' => (new StringType)->setRequired(true)],
                className: SimpleClass::class,
            ),
            new ObjectType(
                properties: ['right' => (new StringType)->setRequired(true)],
                className: SimpleClass::class,
            ),
        ]);

        $this->assertSchemaArraysMatch(
            expected: [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            'left' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => [
                            'left',
                        ],
                    ],
                    [
                        'type' => 'object',
                        'properties' => [
                            'right' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => [
                            'right',
                        ],
                    ],
                ],
            ],
            actual: $finalizedType->toSchema(self::loadConfig()),
            uri: '/test/root-alternatives',
            method: 'response',
        );
    }

    #[Test]
    public function requestBodyMergesNestedResolverShapesWithoutMutatingEitherInput(): void
    {
        $sharedPayload = (new ObjectType(
            properties: ['left' => (new StringType)->setRequired(true)],
            className: SimpleClass::class,
        ))->setRequired(true);
        $sharedRequestBody = new ObjectType(properties: ['payload' => $sharedPayload]);

        $additionalPayload = (new ObjectType(
            properties: ['right' => (new IntegerType)->setRequired(true)],
            className: SimpleClass::class,
        ))->setRequired(true);
        $additionalRequestBody = new ObjectType(properties: ['payload' => $additionalPayload]);

        $finalizedType = $this->finalizer()->finalizeRequestBodyTypes(types: [
            new SharedTypeResolver($sharedRequestBody),
            $additionalRequestBody,
        ]);

        $this->assertInstanceOf(ObjectType::class, $finalizedType);
        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'object',
                'properties' => [
                    'payload' => [
                        'type' => 'object',
                        'properties' => [
                            'left' => [
                                'type' => 'string',
                            ],
                            'right' => [
                                'type' => 'integer',
                            ],
                        ],
                        'required' => [
                            'left',
                            'right',
                        ],
                    ],
                ],
                'required' => [
                    'payload',
                ],
            ],
            actual: $finalizedType->toSchema(self::loadConfig()),
            uri: '/test/request-intersection',
            method: 'request',
        );
        $this->assertArrayNotHasKey('right', $sharedPayload->properties);
        $this->assertArrayNotHasKey('left', $additionalPayload->properties);
    }

    #[Test]
    public function requestBodyUsesCoerciveScalarIntersectionSemantics(): void
    {
        $finalizedType = $this->finalizer()->finalizeRequestBodyTypes(types: [
            new StringType,
            new IntegerType,
        ]);

        $this->assertNotNull($finalizedType);
        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'string',
                'format' => 'integer',
            ],
            actual: $finalizedType->toSchema(self::loadConfig()),
            uri: '/test/request-scalar-intersection',
            method: 'request',
        );
    }

    #[Test]
    public function responseMergeCannotMutateSharedTypeBeforeResolverResultIsCloned(): void
    {
        $sharedProperty = new StringType('shared');
        $sharedResponse = new ObjectType(
            properties: ['value' => $sharedProperty],
            className: SimpleClass::class,
        );

        $finalizedType = $this->finalizer()->finalizeResponseTypes(types: [
            new SharedTypeResolver($sharedResponse),
            new ObjectType(
                properties: ['value' => new StringType('other')],
                className: SimpleClass::class,
            ),
        ]);

        $this->assertInstanceOf(ObjectType::class, $finalizedType);
        $this->assertSame($sharedProperty, $sharedResponse->properties['value']);
        $this->assertSame('shared', $sharedResponse->properties['value']->value);
    }

    #[Test]
    public function phpDocRequestBodyUsesPayloadFinalization(): void
    {
        $closure =
            /**
             * @request-body object{payload: object{left: string}|object{right: int}}
             */
            function (): string {
                return 'ok';
            };

        $operation = $this->operation($closure);

        $this->assertInstanceOf(RequestBody::class, $operation->requestBody);
        $mediaType = $operation->requestBody->content['application/json'] ?? null;
        $this->assertInstanceOf(MediaType::class, $mediaType);
        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'object',
                'properties' => [
                    'payload' => [
                        'type' => 'object',
                        'properties' => [
                            'left' => [
                                'type' => 'string',
                            ],
                            'right' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
                'required' => [
                    'payload',
                ],
            ],
            actual: $mediaType->type->toSchema(self::loadConfig()),
            uri: '/test/phpdoc-request',
            method: 'request',
        );
    }

    #[Test]
    public function responseAddedDirectlyToRouteUsesPayloadFinalization(): void
    {
        $operation = $this->operation(
            closure: function (): void {},
            configureRoute: function (Route $route): void {
                $route->addResponse(
                    status: 201,
                    contentType: 'application/json',
                    body: new ObjectType(properties: [
                        'payload' => (new UnionType([
                            new ObjectType(
                                properties: ['left' => (new StringType)->setRequired(true)],
                                className: SimpleClass::class,
                            ),
                            new ObjectType(
                                properties: ['right' => (new IntegerType)->setRequired(true)],
                                className: SimpleClass::class,
                            ),
                        ]))->setRequired(true),
                    ]),
                );
            },
        );

        $response = $operation->responses[201] ?? null;
        $this->assertInstanceOf(Response::class, $response);
        $mediaType = $response->content['application/json'] ?? null;
        $this->assertInstanceOf(MediaType::class, $mediaType);
        $this->assertSchemaArraysMatch(
            expected: [
                'type' => 'object',
                'properties' => [
                    'payload' => [
                        'type' => 'object',
                        'properties' => [
                            'left' => [
                                'type' => 'string',
                            ],
                            'right' => [
                                'type' => 'integer',
                            ],
                        ],
                    ],
                ],
                'required' => [
                    'payload',
                ],
            ],
            actual: $mediaType->type->toSchema(self::loadConfig()),
            uri: '/test/route-response',
            method: 'response',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(Closure $closure): array
    {
        $operation = $this->operation($closure);
        $response = $operation->responses[200] ?? null;
        $this->assertInstanceOf(Response::class, $response);

        $mediaType = $response->content['application/json'] ?? null;
        $this->assertInstanceOf(MediaType::class, $mediaType);

        return $mediaType->type->toSchema(self::loadConfig());
    }


    /**
     * @param null|callable(Route): void $configureRoute
     */
    private function operation(Closure $closure, ?callable $configureRoute = null): Operation
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = false;
        $config->data['extensions'] = [
            EachCallbackExtension::class,
            SideEffectMethodExtension::class,
        ];

        $route = new Route(uri: '/test', method: 'post', closure: $closure);

        if ($configureRoute !== null) {
            $configureRoute($route);
        }

        $scope = new Scope(config: $config, route: $route);

        return new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->toOperation();
    }


    private function finalizer(): PayloadTypeFinalizer
    {
        return new PayloadTypeFinalizer(
            scope: new Scope(config: self::loadConfig()),
        );
    }
}

final class CloneCountingType extends Type
{
    public static int $cloneCount = 0;

    public function __clone(): void
    {
        self::$cloneCount++;
    }

    public function toSchema(Config $config): array
    {
        return ['type' => 'string'];
    }
}

final class SharedTypeResolver extends UnresolvedType
{
    public function __construct(
        private readonly Type $resolvedType,
    ) {}

    public function resolve(): Type
    {
        return $this->resolvedType;
    }
}
