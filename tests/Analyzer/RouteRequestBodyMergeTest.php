<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use AutoDoc\Route;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * `Route::getRequestBodyType()` merges the accumulated body types by mutating
 * them in place, so every extra merge compounds the structure — re-merging on
 * each body analysis grew geometrically and hung schema serialization on real
 * projects. Only the operation entrypoint consumes the merge, so only it may
 * trigger one.
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
