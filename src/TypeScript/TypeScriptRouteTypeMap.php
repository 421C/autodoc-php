<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;

class TypeScriptRouteTypeMap
{
    public function __construct(
        public readonly ObjectType $requests = new ObjectType,
        public readonly ObjectType $responses = new ObjectType,
    ) {}

    public function addRequestBody(string $route, string $method, Type $type): void
    {
        $this->methodShape($this->requests, $route, $method)->properties['body'] = $type->setRequired(true);
    }

    public function addRequestWithoutBody(string $route, string $method): void
    {
        $this->routeShape($this->requests, $route)->properties[$method] = (new NullType)->setRequired(true);
    }

    public function addQueryParameter(string $route, string $method, string $name, Type $type): void
    {
        $methodShape = $this->methodShape($this->requests, $route, $method);
        $queryShape = $methodShape->properties['query'] ?? null;

        if (! $queryShape instanceof ObjectType) {
            $queryShape = (new ObjectType)->setRequired(true);
            $methodShape->properties['query'] = $queryShape;
        }

        $queryShape->properties[$name] = $type->setRequired(true);
    }

    public function hasRequest(string $route, string $method): bool
    {
        $routeShape = $this->requests->properties[$route] ?? null;

        return $routeShape instanceof ObjectType && isset($routeShape->properties[$method]);
    }

    public function addResponse(string $route, string $method, int|string $responseCode, Type $type): void
    {
        $this->methodShape($this->responses, $route, $method)->properties[(string) $responseCode] = $type->setRequired(true);
    }

    public function sortRoutes(): void
    {
        ksort($this->requests->properties);
        ksort($this->responses->properties);
    }

    private function routeShape(ObjectType $map, string $route): ObjectType
    {
        $routeShape = $map->properties[$route] ?? null;

        if (! $routeShape instanceof ObjectType) {
            $routeShape = (new ObjectType)->setRequired(true);
            $map->properties[$route] = $routeShape;
        }

        return $routeShape;
    }

    private function methodShape(ObjectType $map, string $route, string $method): ObjectType
    {
        $routeShape = $this->routeShape($map, $route);
        $methodShape = $routeShape->properties[$method] ?? null;

        if (! $methodShape instanceof ObjectType) {
            $methodShape = (new ObjectType)->setRequired(true);
            $routeShape->properties[$method] = $methodShape;
        }

        return $methodShape;
    }
}
