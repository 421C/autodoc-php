<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Route;
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
