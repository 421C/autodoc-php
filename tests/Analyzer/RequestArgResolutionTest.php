<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Extensions\MarkerArgExtension;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class RequestArgResolutionTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function returnPathResolvesLocalVariable(): void
    {
        $schemas = $this->analyze(function (object $r): mixed {
            $allowed = ['a', 'b'];

            // @phpstan-ignore method.notFound
            return $r->markReturn($allowed);
        });

        $this->assertSchemaArraysMatch($this->arrayOfAB(), $schemas['response'], '/test', 'response');
    }

    #[Test]
    public function requestPathResolvesLocalVariable(): void
    {
        $schemas = $this->analyze(function (object $r): void {
            $allowed = ['a', 'b'];

            // @phpstan-ignore method.notFound
            $r->markRequest($allowed);
        });

        $this->assertSchemaArraysMatch($this->arrayOfAB(), $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function requestPathResolvesSpreadOfLocalVariable(): void
    {
        $schemas = $this->analyze(function (object $r): void {
            $allowed = ['a', 'b'];

            // @phpstan-ignore method.notFound
            $r->markRequest([...$allowed]);
        });

        $this->assertSchemaArraysMatch($this->arrayOfAB(), $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function requestPathResolvesLocalVariableInsideArrayLiteral(): void
    {
        $schemas = $this->analyze(function (object $r): void {
            $allowed = ['a', 'b'];

            // @phpstan-ignore method.notFound
            $r->markRequest(['allowed' => $allowed]);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'allowed' => $this->arrayOfAB(),
            ],
            'required' => ['allowed'],
        ], $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function requestBodyDeepMergesNestedShapesSharingTopKey(): void
    {
        // Two request body candidates share the top-level `user` key but carry
        // disjoint nested keys; the merged body should be a deep superset rather
        // than degrading the nested object into an anyOf of {name} | {age}.
        $schemas = $this->analyze(function (object $r, string $name, int $age): void {
            // @phpstan-ignore method.notFound
            $r->markRequest(['user' => ['name' => $name]]);
            // @phpstan-ignore method.notFound
            $r->markRequest(['user' => ['age' => $age]]);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'age' => ['type' => 'integer'],
                    ],
                    'required' => ['name', 'age'],
                ],
            ],
            'required' => ['user'],
        ], $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function requestBodyMergesThreeLevelsDeepAndKeepsNonSharedKeys(): void
    {
        // A different angle: the shared `user.profile` path is nested three levels
        // deep (exercising full recursion), and `verified` appears in only one body
        // — the superset must keep it rather than dropping non-shared keys.
        $schemas = $this->analyze(function (object $r, string $name, int $age, bool $verified): void {
            // @phpstan-ignore method.notFound
            $r->markRequest(['user' => ['profile' => ['name' => $name]], 'verified' => $verified]);
            // @phpstan-ignore method.notFound
            $r->markRequest(['user' => ['profile' => ['age' => $age]]]);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => [
                        'profile' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'age' => ['type' => 'integer'],
                            ],
                            'required' => ['name', 'age'],
                        ],
                    ],
                    'required' => ['profile'],
                ],
                'verified' => ['type' => 'boolean'],
            ],
            'required' => ['user', 'verified'],
        ], $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function requestBodyCrossKindSharedKeyMergesAsUnionNotIntersection(): void
    {
        // An object body (ObjectType {n: int|null}) and an array-shape body share
        // the key `n`. The combined request body should accept what either body
        // sends — a union — not an `allOf` intersection. (Two array-shape bodies
        // that conflict at a key already merge to a union; the cross-kind path
        // must match.)
        $schemas = $this->analyze(function (object $r, int $x, int $y): void {
            // @phpstan-ignore method.notFound
            $r->markRequest(new SimpleClass(1));
            // @phpstan-ignore method.notFound
            $r->markRequest(['n' => $x, 'extra' => $y]);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'n' => ['type' => ['integer', 'null']],
                'extra' => ['type' => 'integer'],
            ],
            'required' => ['n', 'extra'],
        ], $schemas['request'], '/test', 'request');
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayOfAB(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'enum' => ['a', 'b'],
            ],
        ];
    }

    /**
     * @return array{request: array<string, mixed>, response: array<string, mixed>}
     */
    private function analyze(Closure $closure): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;
        $config->data['extensions'] = [MarkerArgExtension::class];

        $route = new Route(uri: '/test', method: 'post', closure: $closure);
        $scope = new Scope(config: $config, route: $route);

        $result = (new PhpCallable(scope: $scope, reflection: new ReflectionFunction($closure)))
            ->analyzeBody(analyzeReturnValue: true, isOperationEntrypoint: true);

        return [
            'request' => $result['requestBodyType']?->toSchema($config) ?? [],
            'response' => $result['analyzedReturnType']?->toSchema($config) ?? [],
        ];
    }
}
