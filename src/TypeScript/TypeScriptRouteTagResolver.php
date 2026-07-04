<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Response;
use AutoDoc\Route;
use Exception;

class TypeScriptRouteTagResolver
{
    public function supports(string $value): bool
    {
        return (bool) preg_match('/^(GET|HEAD|POST|PUT|DELETE|PATCH|CONNECT|OPTIONS|TRACE)\s+(.*)/i', $value);
    }

    public function resolve(string $value, Scope $scope): ResolvedTypeScriptRoute
    {
        $arguments = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $httpMethod = strtoupper($arguments[0]);
        $routeUri = trim($arguments[1], '/');
        $responseStatusOrRequestKeyword = $arguments[2] ?? null;
        $routeLoader = $scope->config->getRouteLoader();
        $route = null;
        $operation = null;

        foreach ($routeLoader->getRoutes() as $routeToCheck) {
            if (trim($routeToCheck->uri, '/') === $routeUri && $routeToCheck->hasMethod($httpMethod)) {
                $route = $routeToCheck;
                $operation = $routeLoader->routeToOperation($route);

                break;
            }
        }

        if (! $route || ! $operation) {
            throw new Exception('Route "' . $httpMethod . ' /' . $routeUri . '" not found');
        }

        if ($responseStatusOrRequestKeyword === 'request') {
            return new ResolvedTypeScriptRoute(
                $route,
                $this->resolveRequestType($operation, $route),
                'Request',
            );
        }

        return new ResolvedTypeScriptRoute(
            $route,
            $this->resolveResponseType($operation, $route, $responseStatusOrRequestKeyword),
            'Response',
        );
    }

    private function resolveRequestType(Operation $operation, Route $route): Type
    {
        if (! $operation->requestBody) {
            throw new Exception('Request body not found for route "' . $this->routeName($route) . '"');
        }

        $type = $this->resolveContentType($operation->requestBody->content);

        if (! $type) {
            throw new Exception('Request type not found for route "' . $this->routeName($route) . '"');
        }

        return $type;
    }

    private function resolveResponseType(Operation $operation, Route $route, ?string $requestedStatus): Type
    {
        if ($requestedStatus && ! str_contains($requestedStatus, '{')) {
            $httpStatus = $requestedStatus;

        } else if (isset($operation->responses[200]) || isset($operation->responses['200'])) {
            $httpStatus = 200;

        } else {
            $httpStatus = array_key_first($operation->responses ?? []);
        }

        if ($httpStatus === null) {
            throw new Exception('Response not found for route "' . $this->routeName($route) . '"');
        }

        $response = $operation->responses[$httpStatus] ?? null;

        if (! $response instanceof Response) {
            throw new Exception('Response status "' . $httpStatus . '" not found for route "' . $this->routeName($route) . '"');
        }

        $type = $this->resolveContentType($response->content ?? []);

        if (! $type) {
            throw new Exception('Response type not found for route "' . $this->routeName($route) . '"');
        }

        return $type;
    }

    /**
     * @param array<string, MediaType> $content
     */
    private function resolveContentType(array $content): ?Type
    {
        return $content['application/json']->type
            ?? $content[array_key_first($content) ?? '']->type
            ?? null;
    }

    private function routeName(Route $route): string
    {
        return strtoupper($route->method) . ' /' . trim($route->uri, '/');
    }
}
