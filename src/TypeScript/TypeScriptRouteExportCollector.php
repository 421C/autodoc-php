<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\AbstractRouteLoader;
use AutoDoc\Config;
use AutoDoc\OpenApi\Path;
use AutoDoc\Route;

/**
 * @phpstan-import-type TypeScriptRouteExportConfig from Config
 */
class TypeScriptRouteExportCollector
{
    public function __construct(
        private readonly AbstractRouteLoader $routeLoader,
    ) {}

    /**
     * @param TypeScriptRouteExportConfig $exportOptions
     * @return array<string, Path>
     */
    public function collect(array $exportOptions): array
    {
        $paths = [];

        foreach ($this->routeLoader->getRoutes() as $sourceRoute) {
            $route = $this->normalize($sourceRoute);

            if (! $this->isRouteAllowed($route, $exportOptions)) {
                continue;
            }

            $operation = $this->routeLoader->routeToOperation($route);

            if ($operation) {
                $paths[$route->uri] ??= new Path;
                $paths[$route->uri]->operations[$route->method] = $operation;
            }
        }

        return $paths;
    }

    private function normalize(Route $route): Route
    {
        $route = clone $route;
        $route->uri = '/' . ltrim($route->uri, '/');
        $route->method = strtolower($route->method);

        return $route;
    }

    /**
     * @param TypeScriptRouteExportConfig $exportOptions
     */
    private function isRouteAllowed(Route $route, array $exportOptions): bool
    {
        if (! $this->isHttpRequestMethodAllowed($route->method, $exportOptions)) {
            return false;
        }

        foreach ($exportOptions['routes'] ?? [] as $routePrefix) {
            if (str_starts_with(ltrim($route->uri, '/'), ltrim($routePrefix, '/'))) {
                return true;
            }
        }

        foreach ($exportOptions['exact_routes'] ?? [] as $exactRoute) {
            if (trim($route->uri, '/') === trim($exactRoute, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param TypeScriptRouteExportConfig $exportOptions
     */
    private function isHttpRequestMethodAllowed(string $method, array $exportOptions): bool
    {
        $requestMethods = $exportOptions['request_methods'] ?? null;

        if (empty($requestMethods)) {
            return true;
        }

        return in_array(strtolower($method), $requestMethods);
    }
}
