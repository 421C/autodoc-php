<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

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
                    $operation->parameters[] = new Parameter(
                        name: $paramName,
                        in: $location,
                        required: $paramType->required,
                        deprecated: $paramType->deprecated,
                        schema: $paramType->toSchema($this->scope->config),
                        type: $paramType,
                    );
                }
            }
        }

        if ($requestBodyType) {
            $requestBodyType = $requestBodyType->unwrapType($this->scope->config);

            if (!($requestBodyType instanceof UnknownType) && !($requestBodyType instanceof ObjectType && empty($requestBodyType->properties))) {
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
