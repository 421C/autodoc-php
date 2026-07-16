<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\Entities\DeepMutationRoot;
use AutoDoc\Tests\TestProject\Entities\NestedPropertyRoot;
use AutoDoc\Tests\TestProject\Extensions\EachCallbackExtension;
use AutoDoc\Tests\TestProject\Extensions\SideEffectMethodExtension;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class CallbackParameterResolutionTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    protected function setUp(): void
    {
        SideEffectMethodExtension::$dispatchLog = [];
    }

    #[Test]
    public function unconditionalCallbackMutationIsRequired(): void
    {
        $schema = $this->analyze(function (object $model) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (object $item): void {
                // @phpstan-ignore method.notFound
                $item->injectAttribute();
            });
        });

        $this->assertSchemaArraysMatch($this->injectedBody(required: true), $schema, '/test', 'response');
    }

    #[Test]
    public function arrowFunctionCallbackMutationIsRequired(): void
    {
        $schema = $this->analyze(function (object $model) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(fn (object $item) => $item->injectAttribute()); // @phpstan-ignore method.notFound
        });

        $this->assertSchemaArraysMatch($this->injectedBody(required: true), $schema, '/test', 'response');
    }

    #[Test]
    public function mutationInsideReturningBranchIsOptional(): void
    {
        // The mutation runs on every execution that takes the branch, so the
        // caller can observe it — but executions that skip the branch cannot.
        $schema = $this->analyze(function (object $model, bool $flag) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (object $item) use ($flag): void {
                if ($flag) {
                    // @phpstan-ignore method.notFound
                    $item->injectAttribute();

                    return;
                }
            });
        });

        $this->assertSchemaArraysMatch($this->injectedBody(required: false), $schema, '/test', 'response');
    }

    #[Test]
    public function mutationAfterEarlyReturnIsOptional(): void
    {
        $schema = $this->analyze(function (object $model, bool $flag) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (object $item) use ($flag): void {
                if ($flag) {
                    return;
                }

                // @phpstan-ignore method.notFound
                $item->injectAttribute();
            });
        });

        $this->assertSchemaArraysMatch($this->injectedBody(required: false), $schema, '/test', 'response');
    }

    #[Test]
    public function mutationInEveryExhaustiveBranchIsRequired(): void
    {
        $schema = $this->analyze(function (object $model, bool $flag) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (object $item) use ($flag): void {
                if ($flag) {
                    // @phpstan-ignore method.notFound
                    $item->injectAttribute();

                    return;
                }

                // @phpstan-ignore method.notFound
                $item->injectAttribute();
            });
        });

        $this->assertSchemaArraysMatch($this->injectedBody(required: true), $schema, '/test', 'response');
    }

    #[Test]
    public function mutationInAlwaysThrowingCallbackIsNotReported(): void
    {
        // The body always throws, so execution never returns to the caller: the
        // mutation before the throw is not observable and must not be reported.
        $schema = $this->analyze(function (object $model) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (object $item): void {
                // @phpstan-ignore method.notFound
                $item->injectAttribute();

                throw new Exception;
            });
        });

        $this->assertSchemaArraysMatch(['type' => 'string'], $schema, '/test', 'response');
    }

    #[Test]
    public function nestedPathMutationIsAppliedAtThatPath(): void
    {
        $schema = $this->analyze(function (NestedPropertyRoot $model) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (NestedPropertyRoot $item): void {
                // @phpstan-ignore method.notFound
                $item->b->injectNested();
            });
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'b' => [
                    'type' => 'object',
                    'properties' => [
                        'c' => ['type' => ['integer', 'null']],
                        'tagged' => ['type' => 'string'],
                    ],
                    'required' => ['c', 'tagged'],
                ],
            ],
            'required' => ['b'],
        ], $schema, '/test', 'response');
    }

    #[Test]
    public function conditionalNestedPathMutationIsOptional(): void
    {
        $schema = $this->analyze(function (NestedPropertyRoot $model, bool $flag) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (NestedPropertyRoot $item) use ($flag): void {
                if ($flag) {
                    // @phpstan-ignore method.notFound
                    $item->b->injectNested();

                    return;
                }
            });
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'b' => [
                    'type' => 'object',
                    'properties' => [
                        'c' => ['type' => ['integer', 'null']],
                        'tagged' => ['type' => 'string'],
                    ],
                    'required' => ['c'],
                ],
            ],
            'required' => ['b'],
        ], $schema, '/test', 'response');
    }

    #[Test]
    public function dynamicPathMutationIsIgnored(): void
    {
        $schema = $this->analyze(function (object $model, string $key) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (object $item) use ($key): void {
                // @phpstan-ignore method.nonObject
                $item->{$key}->injectNested();
            });
        });

        $this->assertSchemaArraysMatch(['type' => 'object'], $schema, '/test', 'response');
    }

    #[Test]
    public function nestedPathMutationDescendsThroughClassBackedProperty(): void
    {
        // A low `max_depth` leaves the intermediate `branch` resolved as a
        // class-only `ObjectType` (properties not materialized). Descending the
        // mutation path must fall back to class lookup for `leaf`, the same way
        // attribute-path narrowing does — otherwise the mutation is dropped.
        $schema = $this->analyze(function (DeepMutationRoot $model) {
            // @phpstan-ignore method.notFound
            return $model->eachItem(function (DeepMutationRoot $item): void {
                // @phpstan-ignore method.notFound
                $item->branch->leaf->injectNested();
            });
        }, maxDepth: 0);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'branch' => [
                    'type' => 'object',
                    'properties' => [
                        'leaf' => [
                            'type' => 'object',
                            'properties' => [
                                'tagged' => ['type' => 'string'],
                            ],
                            'required' => ['tagged'],
                        ],
                    ],
                    'required' => ['leaf'],
                ],
            ],
            'required' => ['branch'],
        ], $schema, '/test', 'response');
    }

    /**
     * @return array<string, mixed>
     */
    private function injectedBody(bool $required): array
    {
        $schema = [
            'type' => 'object',
            'properties' => ['injected' => ['type' => 'string']],
        ];

        if ($required) {
            $schema['required'] = ['injected'];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(Closure $closure, ?int $maxDepth = null): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = false;
        $config->data['extensions'] = [
            EachCallbackExtension::class,
            SideEffectMethodExtension::class,
        ];

        if ($maxDepth !== null) {
            $config->data['max_depth'] = $maxDepth;
        }

        $route = new Route(uri: '/test', method: 'post', closure: $closure);
        $scope = new Scope(config: $config, route: $route);

        $result = new PhpCallable(scope: $scope, reflection: new ReflectionFunction($closure))
            ->analyzeBody(analyzeReturnValue: true, isOperationEntrypoint: true);

        return $result['analyzedReturnType']?->toSchema($config) ?? [];
    }
}
