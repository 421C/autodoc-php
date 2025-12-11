<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
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

    /**
     * @var Type[]
     */
    private array $requestBodyTypes = [];


    public function addRequestBodyType(Type $type): void
    {
        $this->requestBodyTypes[] = $type;
    }

    public function getRequestBodyType(?Config $config = null): ?Type
    {
        $unwrappedType = (new UnionType($this->requestBodyTypes))->unwrapType($config);

        if ($unwrappedType instanceof UnionType) {
            return $unwrappedType->mergeObjectsAndArrayShapes($config)->unwrapType($config);
        }

        return $unwrappedType;
    }


    public function hasMethod(string $method): bool
    {
        return strcasecmp($this->method, $method) === 0;
    }


    public function addResponse(int $status, string $contentType, Type $body): void
    {
        $this->responses[] = [
            'status' => $status,
            'contentType' => $contentType,
            'body' => $body,
        ];
    }
}
