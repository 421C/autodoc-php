<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Extensions\SideEffectFuncExtension;
use AutoDoc\Tests\TestProject\Extensions\SideEffectMethodExtension;
use AutoDoc\Tests\TestProject\Extensions\SideEffectStaticExtension;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class SideEffectExtensionTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    protected function setUp(): void
    {
        SideEffectMethodExtension::$dispatchLog = [];
        SideEffectFuncExtension::$dispatchLog = [];
        SideEffectStaticExtension::$dispatchLog = [];
    }

    #[Test]
    public function handleSideEffectMutatesTargetVarFromStatement(): void
    {
        $schemas = $this->analyze(function (object $model) {
            // @phpstan-ignore method.notFound
            $model->injectAttribute();

            return $model;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => ['injected' => ['type' => 'string']],
            'required' => ['injected'],
        ], $schemas['response'], '/test', 'response');
    }

    #[Test]
    public function handleSideEffectMutatesTargetVarWhenValueUsed(): void
    {
        $schemas = $this->analyze(function (object $model) {
            // @phpstan-ignore method.notFound
            $ignored = $model->injectAttribute();

            return $model;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => ['injected' => ['type' => 'string']],
            'required' => ['injected'],
        ], $schemas['response'], '/test', 'response');
    }

    #[Test]
    public function handleSideEffectFiresOncePerNodeWhenReturnValueResolved(): void
    {
        $this->analyze(function (object $model): array {
            // @phpstan-ignore method.notFound
            $result = $model->injectAttribute();

            return ['result' => $result];
        });

        $this->assertSame(1, $this->dispatchCount('injectAttribute'));
    }

    #[Test]
    public function handleSideEffectFiresOnceDespiteBreakoutPeek(): void
    {
        // A bare call statement is peeked by never-return detection before it is
        // traversed; the peek must not dispatch the side effect (nor double-count).
        $this->analyze(function (object $model) {
            // @phpstan-ignore method.notFound
            $model->injectAttribute();

            return $model;
        });

        $this->assertSame(1, $this->dispatchCount('injectAttribute'));
    }

    #[Test]
    public function handleSideEffectCapturesRequestBodyFromMethodStatement(): void
    {
        $schemas = $this->analyze(function (object $r): void {
            // @phpstan-ignore method.notFound
            $r->captureBody(['token' => 'abc']);
        });

        $this->assertSchemaArraysMatch($this->tokenBody(), $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function handleSideEffectCapturesRequestBodyFromBareFuncStatement(): void
    {
        $schemas = $this->analyze(function (): void {
            // @phpstan-ignore function.notFound
            capture_body_func(['token' => 'abc']);
        });

        $this->assertSchemaArraysMatch($this->tokenBody(), $schemas['request'], '/test', 'request');
    }

    #[Test]
    public function handleSideEffectCapturesRequestBodyFromBareStaticStatement(): void
    {
        $schemas = $this->analyze(function (): void {
            // @phpstan-ignore staticMethod.notFound
            SimpleClass::captureBodyStatic(['token' => 'abc']);
        });

        $this->assertSchemaArraysMatch($this->tokenBody(), $schemas['request'], '/test', 'request');
    }

    private function dispatchCount(string $method): int
    {
        return count(array_filter(SideEffectMethodExtension::$dispatchLog, fn (string $m) => $m === $method));
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenBody(): array
    {
        return [
            'type' => 'object',
            'properties' => ['token' => ['type' => 'string']],
            'required' => ['token'],
        ];
    }

    /**
     * @return array{request: array<string, mixed>, response: array<string, mixed>}
     */
    private function analyze(Closure $closure): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = false;
        $config->data['extensions'] = [
            SideEffectMethodExtension::class,
            SideEffectFuncExtension::class,
            SideEffectStaticExtension::class,
        ];

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
