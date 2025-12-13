<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Parameter;
use AutoDoc\OpenApi\RequestBody;
use AutoDoc\OpenApi\Response;
use Closure;
use ReflectionFunction;

class PhpClosure
{
    public function __construct(
        public Closure $closure,
        public Scope $scope,
    ) {}


    private ?PhpFunction $phpFunction = null;


    public function getPhpFunction(): ?PhpFunction
    {
        if (isset($this->phpFunction)) {
            return $this->phpFunction;
        }

        $this->phpFunction = new PhpFunction(
            new ReflectionFunction($this->closure),
            $this->scope,
        );

        return $this->phpFunction;
    }


    public function toOperation(): Operation
    {
        $operation = new Operation;

        $phpFunction = $this->getPhpFunction();
        $phpDoc = $phpFunction?->getPhpDoc();

        $requestBodyType = null;
        $responseBodyType = null;

        if ($phpDoc) {
            $phpDocResponseTag = $phpDoc->getResponseTag();

            if ($phpDocResponseTag) {
                $responseBodyType = $phpFunction->getTypeFromPhpDocTag($phpDocResponseTag);

            } else {
                $phpDocReturnTag = $phpDoc->getReturnTag();

                if ($phpDocReturnTag) {
                    $responseBodyType = $phpFunction->getTypeFromPhpDocTag($phpDocReturnTag);
                }
            }

            [$operation->summary, $operation->description] = $phpDoc->getSummaryAndDescription();

            $phpDocRequestParams = $phpDoc->getRequestParams();

            $requestBodyType = $phpDocRequestParams['body'];

            foreach (['cookie', 'header', 'path', 'query'] as $location) {
                foreach ($phpDocRequestParams[$location] as $paramName => $paramType) {
                    $operation->parameters[] = Parameter::fromType($paramType, $paramName, $location, $this->scope->config);
                }
            }
        }

        foreach ($this->scope->route->requestQueryParams ?? [] as $paramName => $paramType) {
            $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'query', $this->scope->config);
        }

        foreach ($this->scope->route->requestUrlParams ?? [] as $paramName => $paramType) {
            $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'path', $this->scope->config);
        }

        foreach ($this->scope->route->requestHeaders ?? [] as $paramName => $paramType) {
            $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'header', $this->scope->config);
        }

        foreach ($this->scope->route->requestCookies ?? [] as $paramName => $paramType) {
            $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'cookie', $this->scope->config);
        }

        if ($requestBodyType) {
            $requestBodyType = $requestBodyType->unwrapType($this->scope->config);

            if ($this->scope->route
                && !($requestBodyType instanceof UnknownType)
                && !($requestBodyType instanceof ObjectType && empty($requestBodyType->properties))
            ) {
                if ($this->scope->route->hasMethod('GET') || $this->scope->route->hasMethod('HEAD')) {
                    if ($requestBodyType instanceof ObjectType) {
                        foreach ($requestBodyType->properties as $paramName => $paramType) {
                            $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'query', $this->scope->config);
                        }

                    } else if ($requestBodyType instanceof ArrayType && $requestBodyType->shape) {
                        foreach ($requestBodyType->shape as $paramName => $paramType) {
                            $operation->parameters[] = Parameter::fromType($paramType, (string) $paramName, 'query', $this->scope->config);
                        }
                    }

                } else {
                    $operation->requestBody = new RequestBody(
                        content: [
                            'application/json' => new MediaType(
                                schema: $requestBodyType->toSchema($this->scope->config),
                                type: $requestBodyType,
                            ),
                        ],
                    );
                }
            }
        }

        if (! $responseBodyType) {
            $responseBodyType = $phpFunction?->getTypeFromNativeReturnType()?->unwrapType($this->scope->config);
        }

        if ($responseBodyType && !($responseBodyType instanceof UnknownType)) {
            $operation->responses = [
                '200' => new Response(
                    content: [
                        'application/json' => new MediaType(
                            schema: $responseBodyType->toSchema($this->scope->config),
                            type: $responseBodyType,
                        ),
                    ],
                ),
            ];
        }

        return $operation;
    }
}
