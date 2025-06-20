<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Config;
use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\Workspace;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;

/**
 * @phpstan-type Schema array{
 *     paths: array<string, array<string, array<string, mixed>>>,
 * }
 */
final class OpenApiSchemaTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function checkOpenApiJsonSchema(): void
    {
        $config = $this->loadConfig();

        $config->data['openapi']['show_routes_as_titles'] = false;
        $config->data['openapi']['show_values_for_scalar_types'] = true;
        $config->data['openapi_export_dir'] = __DIR__ . '/../../openapi';

        $workspace = Workspace::getDefault($config);

        $this->assertNotNull($workspace);

        /** @var ?Schema */
        $schema = json_decode($workspace->getJson() ?: '', true);

        $this->assertNotNull($schema);

        foreach ($config->getRouteLoader()->getRoutes() as $route) {
            if ($route->className && $route->classMethod) {
                $this->assertClassMethodMatchesOperationSchema(
                    schema: $schema,
                    className: $route->className,
                    classMethod: $route->classMethod,
                    uri: $route->uri,
                    method: $route->method,
                );

            } else if ($route->closure) {
                $this->assertClosureMatchesOperationSchema(
                    schema: $schema,
                    closure: $route->closure,
                    uri: $route->uri,
                    method: $route->method,
                );

            } else {
                $this->fail();
            }
        }
    }


    /**
     * @param Schema $schema
     * @param class-string $className
     */
    private function assertClassMethodMatchesOperationSchema(array $schema, string $className, string $classMethod, string $uri, string $method): void
    {
        $this->assertTrue(isset($schema['paths'][$uri][$method]), 'Operation schema not found.');

        $reflectionClass = new ReflectionClass($className);

        $expectedSchemaAttribute = $reflectionClass->getMethod($classMethod)->getAttributes(ExpectedOperationSchema::class)[0] ?? null;


        /** @var array<string, mixed> */
        $expected = $expectedSchemaAttribute?->getArguments()[0] ?? [];

        /** @var array<string, mixed> */
        $actual = $schema['paths'][$uri][$method];


        $this->assertSchemaArraysMatch($expected, $actual, $uri, $method);
    }


    /**
     * @param Schema $schema
     */
    private function assertClosureMatchesOperationSchema(array $schema, Closure $closure, string $uri, string $method): void
    {
        $reflectionFunction = new ReflectionFunction($closure);

        $expectedSchemaAttribute = $reflectionFunction->getAttributes(ExpectedOperationSchema::class)[0] ?? null;

        /** @var array<string, mixed> */
        $expected = $expectedSchemaAttribute?->getArguments()[0] ?? [];

        /** @var array<string, mixed> */
        $actual = $schema['paths'][$uri][$method];


        $this->assertSchemaArraysMatch($expected, $actual, $uri, $method);
    }
}
