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
    ) {}


    /**
     * @var Type[]
     */
    private array $requestBodyTypes = [];


    public function addRequestBodyType(Type $type): void
    {
        $this->requestBodyTypes[] = $type;
    }

    public function getRequestBodyType(): ?Type
    {
        return (new UnionType($this->requestBodyTypes))->unwrapType();
    }
}
