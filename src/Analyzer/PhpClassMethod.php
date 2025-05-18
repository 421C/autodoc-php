<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\RequestBody;
use AutoDoc\OpenApi\Response;
use ReflectionException;
use ReflectionMethod;

/**
 * @template TClass of object
 */
class PhpClassMethod
{
    public function __construct(
        /**
         * @var PhpClass<TClass>
         */
        public PhpClass $phpClass,
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

            $requestBodyType = $this->scope->route?->getRequestBodyType();

            if (! $responseBodyType && $methodNodeVisitor->returnTypes) {
                $responseBodyType = (new UnionType($methodNodeVisitor->returnTypes))->unwrapType();
            }

        } else if (! $responseBodyType) {
            $responseBodyType = $phpFunction?->getTypeFromNativeReturnType();
        }

        if ($requestBodyType && !($requestBodyType instanceof UnknownType)) {
            if (! ($requestBodyType instanceof ObjectType && empty($requestBodyType->properties))) {
                $operation->requestBody = new RequestBody(
                    content: [
                        'application/json' => new MediaType($requestBodyType->toSchema($this->scope->config)),
                    ],
                );
            }
        }

        foreach ($this->scope->route->responses ?? [] as $response) {
            $operation->responses[strval($response['status'] ?? 200)] = new Response(
                content: [
                    ($response['contentType'] ?? 'application/json') => new MediaType(($response['body'] ?? new UnknownType)->toSchema($this->scope->config)),
                ],
            );
        }

        if ($responseBodyType && !($responseBodyType instanceof UnknownType)) {
            $operation->responses['200'] = new Response(
                content: [
                    'application/json' => new MediaType($responseBodyType->toSchema($this->scope->config)),
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
            $typeFromPhpDocReturnTag = $phpFunction->getTypeFromPhpDocReturnTag();

            if ($typeFromPhpDocReturnTag) {
                return $typeFromPhpDocReturnTag;
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
                $type = (new UnionType($methodNodeVisitor->returnTypes))->unwrapType();

                if (! ($type instanceof UnknownType)) {
                    return $type;
                }
            }
        }

        return $phpFunction->getTypeFromNativeReturnType() ?? new UnknownType;
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
