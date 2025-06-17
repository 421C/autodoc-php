<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
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
                    $description = $paramType->description;

                    $paramType->description = null;

                    $schema = $paramType->toSchema($this->scope->config);

                    $description = $description ? trim("{$paramType->description}\n\n{$description}") : $paramType->description;

                    $operation->parameters[] = new Parameter(
                        name: $paramName,
                        in: $location,
                        description: $description,
                        required: $paramType->required,
                        deprecated: $paramType->deprecated,
                        schema: $schema,
                    );
                }
            }
        }

        $classFileName = $this->phpClass->getReflection()->getFileName();

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
                $responseBodyType = (new UnionType($methodNodeVisitor->returnTypes))->unwrapType($this->scope->config);
            }

        } else if (! $responseBodyType) {
            $responseBodyType = $phpFunction?->getTypeFromNativeReturnType()?->unwrapType($this->scope->config);
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

        foreach ($this->scope->route->responses ?? [] as $response) {
            $httpStatusCode = strval($response['status'] ?? 200);
            $type = $response['body'] ?? new UnknownType;

            $operation->responses[$httpStatusCode] = new Response(
                content: [
                    ($response['contentType'] ?? 'application/json') => new MediaType(
                        schema: $type->toSchema($this->scope->config),
                        type: $type,
                    ),
                ],
            );
        }

        if ($responseBodyType && !($responseBodyType instanceof UnknownType)) {
            $operation->responses['200'] = new Response(
                content: [
                    'application/json' => new MediaType(
                        schema: $responseBodyType->toSchema($this->scope->config),
                        type: $responseBodyType,
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

        if ($usePhpDocIfAvailable) {
            $typeFromPhpDocReturnTag = $phpFunction->getTypeFromPhpDocReturnTag()?->resolve();

            if ($typeFromPhpDocReturnTag) {
                $isPlainArray = $typeFromPhpDocReturnTag instanceof ArrayType
                    && ! $typeFromPhpDocReturnTag->shape
                    && ! $typeFromPhpDocReturnTag->itemType;

                if (! $isPlainArray) {
                    return $typeFromPhpDocReturnTag;
                }
            }
        }

        if (! $doNotAnalyzeBody && $this->scope->depth <= $this->scope->config->data['max_depth']) {
            $methodNodeVisitor = new ClassMethodNodeVisitor(
                methodName: $this->methodName,
                scope: $this->scope,
                analyzeReturnValue: true,
                args: $this->args,
            );

            $this->phpClass->traverse($methodNodeVisitor);

            if ($methodNodeVisitor->returnTypes) {
                $type = (new UnionType($methodNodeVisitor->returnTypes))->unwrapType($this->scope->config);

                if (! ($type instanceof UnknownType)) {
                    return $type;
                }
            }

            if (! $methodNodeVisitor->targetMethodExists) {
                $parentClass = $this->phpClass->getParent();

                if ($parentClass) {
                    $type = $parentClass->getMethod($this->methodName)->getReturnType($usePhpDocIfAvailable, $doNotAnalyzeBody);

                    if (! ($type instanceof UnknownType)) {
                        return $type;
                    }
                }
            }
        }

        return $phpFunction->getTypeFromNativeReturnType() ?? $typeFromPhpDocReturnTag ?? new UnknownType;
    }


    public function getReflection(): ?ReflectionMethod
    {
        try {
            return $this->phpClass->getReflection()->getMethod($this->methodName);

        } catch (ReflectionException $exception) {
            if ($this->scope->isDebugModeEnabled()) {
                if ($this->scope->config->data['debug']['ignore_dynamic_method_errors'] === false) {
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
