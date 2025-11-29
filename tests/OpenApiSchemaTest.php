<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Config;
use AutoDoc\Route;
use AutoDoc\Tests\Attributes\ExpectedOperationSchema;
use AutoDoc\Tests\TestProject\Extensions\NotFoundExceptionExtension;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\Workspace;
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
    public function showValuesForScalarTypes(): void
    {
        $config = self::loadConfig();

        $config->data['openapi']['show_routes_as_titles'] = false;
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $config->data['extensions'] = [
            NotFoundExceptionExtension::class,
        ];

        $this->checkOpenApiJsonSchema($config, __FUNCTION__);
    }


    #[Test]
    public function resolvePartialArrayShapes(): void
    {
        $config = self::loadConfig();

        $config->data['openapi']['show_routes_as_titles'] = false;
        $config->data['openapi']['show_values_for_scalar_types'] = false;
        $config->data['arrays']['resolve_partial_shapes'] = true;

        $config->data['extensions'] = [
            NotFoundExceptionExtension::class,
        ];

        $this->checkOpenApiJsonSchema($config, __FUNCTION__);
    }


    private function checkOpenApiJsonSchema(Config $config, string $testMethod): void
    {
        $config->data['openapi_export_dir'] = __DIR__ . '/../../openapi';

        $workspace = Workspace::getDefault($config);

        $this->assertNotNull($workspace);

        /** @var ?Schema */
        $schema = json_decode($workspace->getJson() ?: '', true);

        $this->assertNotNull($schema);

        foreach ($config->getRouteLoader()->getRoutes() as $route) {
            $this->assertTrue(isset($schema['paths'][$route->uri][$route->method]), 'Operation schema not found.');

            $this->assertOperationSchemaMatchesExpected(
                schema: $schema,
                route: $route,
                testMethod: $testMethod,
            );
        }
    }


    /**
     * @param Schema $schema
     */
    private function assertOperationSchemaMatchesExpected(array $schema, Route $route, string $testMethod): void
    {
        if ($route->className && $route->classMethod) {
            $reflectionClass = new ReflectionClass($route->className);

            $expectedSchemaAttributes = $reflectionClass->getMethod($route->classMethod)->getAttributes(ExpectedOperationSchema::class);

        } else if ($route->closure) {
            $reflectionFunction = new ReflectionFunction($route->closure);

            $expectedSchemaAttributes = $reflectionFunction->getAttributes(ExpectedOperationSchema::class);

        } else {
            $this->fail();
        }

        foreach ($expectedSchemaAttributes as $attribute) {
            if ($testMethod === $attribute->getArguments()[0]) {
                /** @var array<string, mixed> */
                $expected = $attribute->getArguments()[1] ?? [];

                /** @var array<string, mixed> */
                $actual = $schema['paths'][$route->uri][$route->method];

                $this->assertSchemaArraysMatch($expected, $actual, $route->uri, $route->method);
            }
        }
    }
}
