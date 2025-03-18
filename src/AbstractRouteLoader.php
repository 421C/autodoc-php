<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\Analyzer\PhpClosure;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Exceptions\AutoDocException;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Path;
use Exception;
use Throwable;


abstract class AbstractRouteLoader
{
    public function __construct(
        protected Config $config,
    ) {}


    /**
     * @return iterable<Route>
     */
    abstract public function getRoutes(): iterable;


    /**
     * @param array{start?: (callable(Route): void), end?: (callable(Route): void)}|null $reportProgress
     * @return array<string, Path>
     */
    public function getOpenApiPaths(?array $reportProgress = null): array
    {
        $paths = [];

        foreach ($this->getRoutes() as $route) {
            $route->uri = '/' . ltrim($route->uri, '/');
            $route->method = strtolower($route->method);

            if (! $this->isRouteAllowed($route)) {
                continue;
            }

            if (isset($reportProgress['start'])) {
                $reportProgress['start']($route);
            }

            $operation = $this->routeToOperation($route);

            if ($operation) {
                if ($this->config->data['openapi']['show_routes_as_titles'] ?? false) {
                    $operation->description = trim($operation->summary . PHP_EOL . PHP_EOL . $operation->description);
                    $operation->summary = trim($route->uri, '/');
                }

                $paths[$route->uri] ??= new Path;

                $paths[$route->uri]->operations[$route->method] = $operation;
            }

            if (isset($reportProgress['end'])) {
                $reportProgress['end']($route);
            }
        }

        return $paths;
    }


    protected function routeToOperation(Route $route): ?Operation
    {
        $operation = null;

        $scope = new Scope(
            config: $this->config,
            route: $route,
        );

        try {
            if ($route->className && $route->classMethod) {
                if (! class_exists($route->className)) {
                    throw new Exception('Class "' . $route->className . '" not found');
                }

                $scope->className = $route->className;
                $scope->methodName = $route->classMethod;

                $operation = $scope->getPhpClass($route->className)->getMethod($route->classMethod)->toOperation();

            } else if ($route->closure) {
                $scope = new Scope(
                    config: $this->config,
                    route: $route,
                );

                $phpClosure = new PhpClosure($route->closure, $scope);

                $operation = $phpClosure->toOperation();
            }

        } catch (Throwable $exception) {
            if ($scope->isDebugModeEnabled()) {
                throw new AutoDocException('Error analyzing route "' . $route->uri . '": ', $exception);
            }
        }

        if (! $operation) {
            return null;
        }

        return (new ExtensionHandler($scope))->handleOperationExtensions($operation, $route, $scope);
    }


    public function isRouteAllowed(Route $route): bool
    {
        if (! $this->isHttpRequestMethodAllowed($route->method)) {
            return false;
        }

        foreach ($this->config->selectedWorkspace['routes'] ?? [] as $routePrefix) {
            if (str_starts_with(ltrim($route->uri, '/'), ltrim($routePrefix, '/'))) {
                return true;
            }
        }

        return false;
    }


    public function isHttpRequestMethodAllowed(string $method): bool
    {
        if (empty($this->config->selectedWorkspace['request_methods'])) {
            return true;
        }

        return in_array(strtolower($method), $this->config->selectedWorkspace['request_methods']);
    }
}
