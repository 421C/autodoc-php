<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Route;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * Only the operation entrypoint finalizes accumulated request-body types. The
 * finalizer owns its result, so repeated reads cannot compound the recorded
 * shapes or mutate types retained by extensions and analyzer caches.
 */
final class RouteRequestBodyMergeTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function plainReturnTypeAnalysisDoesNotMergeTheRequestBody(): void
    {
        $route = new RouteWithMergeCount('/api/test/request-body-merge', 'post');
        $scope = new Scope(self::loadConfig(), route: $route);

        new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction(function (): string {
                return 'x';
            }),
        )->getReturnType();

        $this->assertSame(0, $route->mergeCount);
    }

    #[Test]
    public function buildingAnOperationMergesTheRequestBodyOnce(): void
    {
        $route = new RouteWithMergeCount('/api/test/request-body-merge', 'post');
        $scope = new Scope(self::loadConfig(), route: $route);

        new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction(function (): string {
                return 'x';
            }),
        )->toOperation();

        $this->assertSame(1, $route->mergeCount);
    }

    #[Test]
    public function repeatedFinalizationIsStableAndDoesNotMutateRecordedShapes(): void
    {
        $route = new Route('/api/test/request-body-merge', 'post');
        $scope = new Scope(self::loadConfig(), route: $route);
        $leftPayload = (new ObjectType(
            properties: ['left' => (new StringType)->setRequired(true)],
        ))->setRequired(true);
        $rightPayload = (new ObjectType(
            properties: ['right' => (new IntegerType)->setRequired(true)],
        ))->setRequired(true);

        $route->addRequestBodyType(new ObjectType(properties: ['payload' => $leftPayload]));
        $route->addRequestBodyType(new ObjectType(properties: ['payload' => $rightPayload]));

        $first = $route->getRequestBodyType($scope);
        $second = $route->getRequestBodyType($scope);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->toSchema($scope->config), $second->toSchema($scope->config));
        $this->assertArrayNotHasKey('right', $leftPayload->properties);
        $this->assertArrayNotHasKey('left', $rightPayload->properties);
    }
}

class RouteWithMergeCount extends Route
{
    public int $mergeCount = 0;

    public function getRequestBodyType(Scope $scope): ?Type
    {
        $this->mergeCount++;

        return parent::getRequestBodyType($scope);
    }
}
