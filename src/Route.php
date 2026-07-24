<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\Analyzer\PayloadTypeFinalizer;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use Closure;

class Route
{
    public function __construct(
        public string $uri,
        public string $method,
        /**
         * @var ?class-string
         */
        public ?string $className = null,
        public ?string $classMethod = null,
        public ?Closure $closure = null,

        /**
         * Additional metadata one can use to pass data from RouteLoader to extensions
         *
         * @var array<mixed>
         */
        public array $meta = [],
    ) {}


    /**
     * @var array{
     *     status?: int,
     *     contentType?: string,
     *     body?: Type,
     * }[]
     */
    public array $responses = [];

    /** @var array<string, Type> */
    public array $requestQueryParams = [];

    /** @var array<string, Type> */
    public array $requestUrlParams = [];

    /** @var array<string, Type> */
    public array $requestHeaders = [];

    /** @var array<string, Type> */
    public array $requestCookies = [];

    /** @var list<Type> */
    private array $requestBodyTypes = [];


    public function addResponse(int $status, string $contentType, Type $body): void
    {
        $this->responses[] = [
            'status' => $status,
            'contentType' => $contentType,
            'body' => $body,
        ];
    }


    public function addRequestBodyType(Type $type): void
    {
        $this->requestBodyTypes[] = $type;
    }

    public function getRequestBodyType(Scope $scope): ?Type
    {
        return new PayloadTypeFinalizer(scope: $scope)->finalizeRequestBodyTypes(
            types: $this->requestBodyTypes,
        );
    }


    public function hasMethod(string $method): bool
    {
        return strcasecmp($this->method, $method) === 0;
    }
}
