<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Parameter;
use AutoDoc\OpenApi\RequestBody;
use AutoDoc\OpenApi\Response;
use ReflectionException;
use ReflectionMethod;

/**
 * @template-covariant TClass of object
 */
class PhpClassMethod
{
    public function __construct(
        /**
         * @var PhpClass<TClass>
         */
        public readonly PhpClass $phpClass,
        public string $methodName,
        public Scope $scope,

        /**
         * @var PhpFunctionArgument[]
         */
        private array $args = [],
    ) {}


    private ?PhpFunction $phpFunction = null;


    public function toOperation(): Operation
    {
        $operation = new Operation;

        $requestBodyType = null;
        $responseBodyType = null;

        $phpFunction = $this->getPhpFunction();
        $phpDoc = $phpFunction?->getPhpDoc();

        $phpDocReturnType = null;

        if ($phpDoc) {
            $phpDocResponseTag = $phpDoc->getResponseTag();

            if ($phpDocResponseTag) {
                $responseBodyType = $phpFunction->getTypeFromPhpDocTag($phpDocResponseTag);

            } else {
                $phpDocReturnTag = $phpDoc->getReturnTag();

                if ($phpDocReturnTag) {
                    $phpDocReturnType = $phpFunction->getTypeFromPhpDocTag($phpDocReturnTag)?->resolve();
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

        $classFileName = $this->phpClass->getReflection()->getFileName();
        $analyzedReturnType = null;

        if ($classFileName) {
            $methodNodeVisitor = new ClassMethodNodeVisitor(
                methodName: $this->methodName,
                scope: $this->scope,
                analyzeReturnValue: $responseBodyType === null,
                isOperationEntrypoint: true,
            );

            $this->phpClass->traverse($methodNodeVisitor);

            $requestBodyType ??= $this->scope->route?->getRequestBodyType($this->scope->config);

            if (! $responseBodyType && $methodNodeVisitor->returnTypes) {
                $analyzedReturnType = (new UnionType($methodNodeVisitor->returnTypes))->unwrapType($this->scope->config);
            }
        }

        $queryParamNames = array_flip(array_map(fn ($param) => $param->name, array_filter($operation->parameters ?? [], fn ($param) => $param instanceof Parameter && $param->in === 'query')));
        $urlParamNames = array_flip(array_map(fn ($param) => $param->name, array_filter($operation->parameters ?? [], fn ($param) => $param instanceof Parameter && $param->in === 'path')));
        $headerNames = array_flip(array_map(fn ($param) => $param->name, array_filter($operation->parameters ?? [], fn ($param) => $param instanceof Parameter && $param->in === 'header')));
        $cookieNames = array_flip(array_map(fn ($param) => $param->name, array_filter($operation->parameters ?? [], fn ($param) => $param instanceof Parameter && $param->in === 'cookie')));

        foreach ($this->scope->route->requestQueryParams ?? [] as $paramName => $paramType) {
            if (! isset($queryParamNames[$paramName])) {
                $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'query', $this->scope->config);
            }
        }

        foreach ($this->scope->route->requestUrlParams ?? [] as $paramName => $paramType) {
            if (! isset($urlParamNames[$paramName])) {
                $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'path', $this->scope->config);
            }
        }

        foreach ($this->scope->route->requestHeaders ?? [] as $paramName => $paramType) {
            if (! isset($headerNames[$paramName])) {
                $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'header', $this->scope->config);
            }
        }

        foreach ($this->scope->route->requestCookies ?? [] as $paramName => $paramType) {
            if (! isset($cookieNames[$paramName])) {
                $operation->parameters[] = Parameter::fromType($paramType, $paramName, 'cookie', $this->scope->config);
            }
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
                    $contentType = $requestBodyType->getContentType();

                    foreach ($operation->parameters ?? [] as $param) {
                        if ($param instanceof Parameter
                            && $param->in === 'header'
                            && strcasecmp($param->name, 'Content-Type') === 0
                            && $param->type instanceof StringType
                            && is_string($param->type->value)
                        ) {
                            $contentType = $param->type->value;
                        }
                    }

                    $operation->requestBody = new RequestBody(
                        content: [
                            $contentType => new MediaType(
                                schema: $requestBodyType->toSchema($this->scope->config),
                                type: $requestBodyType,
                            ),
                        ],
                    );
                }
            }
        }

        if (! $responseBodyType) {
            $responseBodyType = $phpFunction?->getReturnType(
                analyzedType: $analyzedReturnType,
                phpDocType: $phpDocReturnType,
            );
        }

        // Create a response from analyzed return type
        if ($responseBodyType && !($responseBodyType instanceof UnknownType)) {
            $responseTypes = $responseBodyType instanceof UnionType
                ? $responseBodyType->types
                : [$responseBodyType];

            /** @var array<int, Type[]> */
            $typesByStatusCode = [];

            foreach ($responseTypes as $type) {
                $statusCode = $type->getHttpStatusCode();
                $typesByStatusCode[$statusCode][] = $type;
            }

            foreach ($typesByStatusCode as $httpStatusCode => $types) {
                $responseType = count($types) === 1
                    ? $types[0]
                    : (new UnionType($types))->unwrapType($this->scope->config);

                $contentType = $responseType->getContentType();

                $operation->responses[$httpStatusCode] = new Response(
                    content: [
                        $contentType => new MediaType(
                            schema: $responseType->toSchema($this->scope->config),
                            type: $responseType,
                        ),
                    ],
                );
            }
        }

        // Add responses attached to Route object
        foreach ($this->scope->route->responses ?? [] as $response) {
            $type = $response['body'] ?? new UnknownType;
            $httpStatusCode = $response['status'] ?? $type->getHttpStatusCode();
            $contentType = $response['contentType'] ?? $type->getContentType();

            $operation->responses[$httpStatusCode] = new Response(
                content: [
                    $contentType => new MediaType(
                        schema: $type->toSchema($this->scope->config),
                        type: $type,
                    ),
                ],
            );
        }

        return $operation;
    }


    public function getReturnType(bool $usePhpDocIfAvailable = true, bool $doNotAnalyzeBody = false): Type
    {
        $phpFunction = $this->getPhpFunction();

        if (! $phpFunction) {
            return new UnknownType;
        }

        $phpDocReturnType = null;
        $analyzedReturnType = null;

        if ($usePhpDocIfAvailable) {
            $phpDocReturnType = $phpFunction->getTypeFromPhpDocReturnTag()?->resolve();

            if ($phpDocReturnType instanceof UnknownType) {
                $phpDocReturnType = null;
            }
        }

        $isPhpDocReturnTypePlainArray = $phpDocReturnType instanceof ArrayType
            && ! $phpDocReturnType->shape
            && ! $phpDocReturnType->itemType;

        if (! $doNotAnalyzeBody && $this->scope->depth <= $this->scope->config->data['max_depth']) {
            $analyzeReturnType = ! $phpDocReturnType || $isPhpDocReturnTypePlainArray;

            $methodNodeVisitor = new ClassMethodNodeVisitor(
                methodName: $this->methodName,
                scope: $this->scope,
                analyzeReturnValue: $analyzeReturnType,
                args: $this->args,
            );

            $this->phpClass->traverse($methodNodeVisitor);

            if ($methodNodeVisitor->returnTypes) {
                $analyzedReturnType = (new UnionType($methodNodeVisitor->returnTypes))->unwrapType($this->scope->config);

            } else if (! $methodNodeVisitor->targetMethodExists && $analyzeReturnType) {
                $methodFoundInTrait = false;

                foreach ($this->phpClass->getReflection()->getTraits() as $traitName => $traitReflection) {
                    /** @var class-string $traitName */

                    $methodInTrait = $this->scope
                        ->getPhpClassInDeeperScope($traitName)
                        ->getMethod($this->methodName);

                    if ($methodInTrait->exists()) {
                        $methodFoundInTrait = true;
                        $analyzedReturnType = $methodInTrait->getReturnType($usePhpDocIfAvailable, $doNotAnalyzeBody);
                        break;
                    }
                }

                if (! $methodFoundInTrait) {
                    $parentClass = $this->phpClass->getParent();

                    if ($parentClass) {
                        $analyzedReturnType = $parentClass->getMethod($this->methodName)->getReturnType($usePhpDocIfAvailable, $doNotAnalyzeBody);
                    }
                }
            }
        }

        return $phpFunction->getReturnType(
            analyzedType: $analyzedReturnType,
            phpDocType: $phpDocReturnType,
        );
    }


    public function getReflection(): ?ReflectionMethod
    {
        try {
            return $this->phpClass->getReflection()->getMethod($this->methodName);

        } catch (ReflectionException $exception) {
            if ($this->scope->isDebugModeEnabled()) {
                $ignoreError = $this->scope->config->data['debug']['ignore_dynamic_method_errors'] ?? true;

                if (! $ignoreError && trait_exists($this->phpClass->className)) {
                    $ignoreError = $this->scope->config->data['debug']['ignore_unknown_method_errors_in_traits'] ?? true;
                }

                if (! $ignoreError) {
                    throw $exception;
                }
            }
        }

        return null;
    }


    public function getPhpFunction(): ?PhpFunction
    {
        if (isset($this->phpFunction)) {
            return $this->phpFunction;
        }

        $reflectionMethod = $this->getReflection();

        if ($reflectionMethod) {
            $this->phpFunction = new PhpFunction($reflectionMethod, $this->scope, $this->args);
        }

        return $this->phpFunction;
    }


    public function exists(): bool
    {
        return $this->phpClass->getReflection()->hasMethod($this->methodName);
    }
}
