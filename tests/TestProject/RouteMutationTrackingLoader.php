<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject;

use AutoDoc\AbstractRouteLoader;
use AutoDoc\Route;
use LogicException;

class RouteMutationTrackingLoader extends AbstractRouteLoader
{
    public static ?Route $route = null;

    public function getRoutes(): iterable
    {
        if (! self::$route) {
            throw new LogicException('Route mutation test route is not configured.');
        }

        yield self::$route;
    }
}
