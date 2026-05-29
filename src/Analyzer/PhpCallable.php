<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedClassType;
use AutoDoc\DataTypes\UnresolvedPhpDocType;
use AutoDoc\DataTypes\UnresolvedReflectionType;
use AutoDoc\OpenApi\MediaType;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Parameter;
use AutoDoc\OpenApi\RequestBody;
use AutoDoc\OpenApi\Response;
use Exception;
use Override;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver as PhpParserNameResolver;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use ReflectionException;
use ReflectionFunctionAbstract;

class PhpCallable
{
    private readonly ?ReflectionFunctionAbstract $reflection;
    private readonly ArgumentList $args;

    public function __construct(
        public Scope $scope,
        ?ReflectionFunctionAbstract $reflection = null,
        ?ArgumentList $args = null,
        private readonly Node\Expr\Closure|Node\Expr\ArrowFunction|null $node = null,

        /**
         * @var ?PhpClass<object>
         */
        private readonly ?PhpClass $phpClass = null,
        private readonly ?string $methodName = null,
    ) {
        $this->args = $args ?? new ArgumentList($this->scope);

        if (! $reflection && $this->phpClass && $this->methodName) {
            try {
                $reflection = $this->phpClass->getReflection()->getMethod($this->methodName);

            } catch (ReflectionException) {
            }
        }

        $this->reflection = $reflection;
    }

    private ?PhpDoc $docComment = null;

    /** @var ?array<string, int> */
    private ?array $parameterNames = null;


    /**
     * Resolve the return type of an inline callable (closure/arrow function).
     * Used by CallableType when the callable is invoked with arguments.
     */
    public function resolveReturnType(ArgumentList $args, ?Node $callerNode = null): Type
    {
        if (! $this->node) {
            return new UnknownType;
        }

        $traverser = new NodeTraverser;
        $functionScope = $this->scope->createChildScope();

        $functionScope->callerNode = $callerNode;

        $nodeVisitor = new FunctionNodeVisitor(
            scope: $functionScope,
            analyzeReturnValue: true,
            args: $args,
            parentScope: $this->scope,
        );

        $traverser->addVisitor($nodeVisitor);
        $traverser->traverse([$this->node]);

        $returnType = new UnionType($nodeVisitor->returnTypes);

        return $returnType->unwrapType($this->scope->config);
    }


    public function getReturnType(bool $usePhpDocIfAvailable = true, bool $doNotAnalyzeBody = false): Type
    {
        if ($this->phpClass && $this->methodName && ! $this->reflection) {
            $methodTags = $this->phpClass->getPhpDoc()?->getMethodTags();

            if (isset($methodTags[$this->methodName])) {
                return $methodTags[$this->methodName]->returnType ?? new UnknownType;
            }

            if ($this->scope->isDebugModeEnabled()) {
                $ignoreError = $this->scope->config->data['debug']['ignore_dynamic_method_errors'] ?? true;

                if (! $ignoreError && trait_exists($this->phpClass->className)) {
                    $ignoreError = $this->scope->config->data['debug']['ignore_unknown_method_errors_in_traits'] ?? true;
                }

                if (! $ignoreError) {
                    throw new Exception('Method `' . $this->methodName . '` does not exist on class `' . $this->phpClass->className . '` and no `@method` annotations found.');
                }
            }

            return new UnknownType;
        }

        $phpDocReturnType = null;

        if ($usePhpDocIfAvailable) {
            $phpDocReturnType = $this->getTypeFromPhpDocReturnTag()?->resolve();

            if ($phpDocReturnType instanceof UnknownType) {
                $phpDocReturnType = null;
            }
        }

        $analyzedReturnType = null;

        if (! $doNotAnalyzeBody && $this->scope->depth <= $this->scope->config->data['max_depth']) {
            $analyzeReturnValue = ! $phpDocReturnType
                || ($phpDocReturnType instanceof ArrayType && ! $phpDocReturnType->shape && ! $phpDocReturnType->itemType);

            if (! $analyzeReturnValue
                && ($this->scope->config->data['arrays']['deep_shape_inference'] ?? false)
            ) {
                if ($phpDocReturnType instanceof ArrayType
                    && ! $phpDocReturnType->shape
                ) {
                    $phpDocReturnType->itemType = $phpDocReturnType->itemType?->unwrapType($this->scope->config);

                    if ($phpDocReturnType->itemType instanceof ArrayType
                        && $phpDocReturnType->itemType->shape
                    ) {
                        $analyzeReturnValue = false;

                    } else {
                        $analyzeReturnValue = true;
                    }
                }
            }

            $analyzedReturnType = $this->analyzeBody(
                analyzeReturnValue: $analyzeReturnValue,
                usePhpDocIfAvailable: $usePhpDocIfAvailable,
                doNotAnalyzeBody: $doNotAnalyzeBody,
            )['analyzedReturnType'];
        }

        return $this->combineReturnTypes(
            analyzedType: $analyzedReturnType,
            phpDocType: $phpDocReturnType,
        );
    }


    /**
     * Combine native return type, PHPDoc type, and analyzed type into a final return type.
     */
    public function combineReturnTypes(?Type $analyzedType = null, ?Type $phpDocType = null): Type
    {
        if ($this->reflection?->getName() === 'base64_encode') {
            return new StringType(format: 'byte');
        }

        $nativeType = $this->getTypeFromNativeReturnType();
        $phpDocType = $phpDocType?->unwrapType($this->scope->config);

        if ($nativeType) {
            $resultingReturnType = $nativeType->unwrapType($this->scope->config);

            if ($phpDocType) {
                $resultingReturnType = $resultingReturnType->getSubType($phpDocType, $this->scope->config);
            }

            if ($analyzedType) {
                $resultingReturnType = $resultingReturnType->getSubType($analyzedType, $this->scope->config);
            }

        } else if ($phpDocType) {
            $resultingReturnType = $phpDocType;

            if ($analyzedType) {
                $resultingReturnType = $resultingReturnType->getSubType($analyzedType, $this->scope->config);
            }

        } else {
            $resultingReturnType = $analyzedType;
        }

        return $resultingReturnType ?? new UnknownType;
    }


    /**
     * Analyze the function/method body and return the analyzed types.
     *
     * @return array{
     *     analyzedReturnType: ?Type,
     *     requestBodyType: ?Type,
     * }
     */
    public function analyzeBody(
        bool $analyzeReturnValue,
        bool $isOperationEntrypoint = false,
        bool $usePhpDocIfAvailable = true,
        bool $doNotAnalyzeBody = false,
    ): array {
        if ($this->phpClass && $this->methodName) {
            return $this->analyzeClassMethodBody($analyzeReturnValue, $isOperationEntrypoint, $usePhpDocIfAvailable, $doNotAnalyzeBody);
        }

        if ($this->node || $this->reflection) {
            return $this->analyzeClosureBody($analyzeReturnValue, $isOperationEntrypoint);
        }

        return ['analyzedReturnType' => null, 'requestBodyType' => null];
    }


    /**
     * @return array{analyzedReturnType: ?Type, requestBodyType: ?Type}
     */
    private function analyzeClassMethodBody(
        bool $analyzeReturnValue,
        bool $isOperationEntrypoint,
        bool $usePhpDocIfAvailable,
        bool $doNotAnalyzeBody,
    ): array {
        if (! $this->phpClass || ! $this->methodName) {
            return ['analyzedReturnType' => null, 'requestBodyType' => null];
        }

        $classFileName = $this->phpClass->getReflection()->getFileName();

        if (! $classFileName) {
            return ['analyzedReturnType' => null, 'requestBodyType' => null];
        }

        $methodNodeVisitor = new FunctionNodeVisitor(
            scope: $this->scope,
            analyzeReturnValue: $analyzeReturnValue,
            args: $this->args,
            isOperationEntrypoint: $isOperationEntrypoint,
            methodName: $this->methodName,
        );

        $this->phpClass->traverse($methodNodeVisitor);

        $requestBodyType = $this->scope->route?->getRequestBodyType($this->scope->config);

        $analyzedReturnType = null;

        if ($analyzeReturnValue && $methodNodeVisitor->returnTypes) {
            $analyzedReturnType = new UnionType($methodNodeVisitor->returnTypes)->unwrapType($this->scope->config);

        } else if ($analyzeReturnValue && ! $methodNodeVisitor->targetMethodExists) {
            $methodFoundInTrait = false;

            foreach (array_keys($this->phpClass->getReflection()->getTraits()) as $traitName) {
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

        return [
            'analyzedReturnType' => $analyzedReturnType,
            'requestBodyType' => $requestBodyType,
        ];
    }


    /**
     * @return array{analyzedReturnType: ?Type, requestBodyType: ?Type}
     */
    private function analyzeClosureBody(bool $analyzeReturnValue, bool $isOperationEntrypoint): array
    {
        $node = $this->node;

        if (! $node) {
            $node = $this->getNodeFromReflection();
        }

        if (! $node) {
            return ['analyzedReturnType' => null, 'requestBodyType' => null];
        }

        $functionScope = $this->scope->createChildScope();

        $nodeVisitor = new FunctionNodeVisitor(
            scope: $functionScope,
            analyzeReturnValue: $analyzeReturnValue,
            args: $this->args,
            isOperationEntrypoint: $isOperationEntrypoint,
            parentScope: $this->scope,
        );

        $traverser = new NodeTraverser;
        $traverser->addVisitor($nodeVisitor);
        $traverser->traverse([$node]);

        $requestBodyType = $this->scope->route?->getRequestBodyType($this->scope->config);

        $analyzedReturnType = null;

        if ($analyzeReturnValue && $nodeVisitor->returnTypes) {
            $analyzedReturnType = new UnionType($nodeVisitor->returnTypes)->unwrapType($this->scope->config);
        }

        return [
            'analyzedReturnType' => $analyzedReturnType,
            'requestBodyType' => $requestBodyType,
        ];
    }


    public function getReflection(): ?ReflectionFunctionAbstract
    {
        return $this->reflection;
    }


    private function getNodeFromReflection(): Node\Expr\Closure|Node\Expr\ArrowFunction|Node\Stmt\Function_|null
    {
        $fileName = $this->reflection?->getFileName();
        $startLine = $this->reflection?->getStartLine();

        if (! $fileName || ! $startLine) {
            return null;
        }

        $code = file_get_contents($fileName);

        if (! $code) {
            return null;
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);

        if (! $ast) {
            return null;
        }

        // Resolve `use` imports so class references inside the closure (e.g.
        // `instanceof Foo`) become fully qualified — there is no surrounding
        // class scope to resolve them otherwise.
        $nameResolverTraverser = new NodeTraverser;
        $nameResolverTraverser->addVisitor(new PhpParserNameResolver);
        $ast = $nameResolverTraverser->traverse($ast);

        $finder = new class ($startLine) extends \PhpParser\NodeVisitorAbstract
        {
            public function __construct(
                private readonly int $startLine,
            ) {}

            public Node\Expr\Closure|Node\Expr\ArrowFunction|Node\Stmt\Function_|null $foundNode = null;

            /**
             * @return null|\PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN
             */
            #[Override]
            public function enterNode(Node $node)
            {
                if ($this->foundNode) {
                    return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                    || $node instanceof Node\Stmt\Function_
                ) {
                    $functionKeywordLine = $node->attrGroups
                        ? end($node->attrGroups)->getEndLine()
                        : $node->getStartLine();

                    if ($functionKeywordLine === $this->startLine) {
                        $this->foundNode = $node;

                        return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->foundNode;
    }




    public function exists(): bool
    {
        if ($this->phpClass && $this->methodName) {
            return $this->phpClass->getReflection()->hasMethod($this->methodName);
        }

        return $this->reflection !== null || $this->node !== null;
    }


    public function getPhpDoc(): ?PhpDoc
    {
        if ($this->docComment !== null) {
            return $this->docComment;
        }

        $comment = $this->reflection?->getDocComment();

        if ($comment) {
            $this->docComment = new PhpDoc($comment, $this->scope);
        }

        return $this->docComment;
    }


    public function getTypeFromPhpDocTag(PhpDocTagValueNode $tagValueNode): ?UnresolvedPhpDocType
    {
        $phpDoc = $this->getPhpDoc();

        if (! $phpDoc) {
            return null;
        }

        return $phpDoc->getTypeFromPhpDocTag($tagValueNode, $this->fillTemplateTypesFromParameters());
    }


    public function getTypeFromPhpDocReturnTag(): ?UnresolvedPhpDocType
    {
        $phpDocReturnTag = $this->getPhpDoc()?->getReturnTag();

        if (! $phpDocReturnTag) {
            return null;
        }

        return $this->getTypeFromPhpDocTag($phpDocReturnTag);
    }


    public function getTypeFromNativeReturnType(): ?UnresolvedReflectionType
    {
        $reflectionReturnType = $this->reflection?->getReturnType();

        if (! $reflectionReturnType) {
            return null;
        }

        return new UnresolvedReflectionType($reflectionReturnType, $this->scope);
    }


    public function getDefaultArgumentValue(string $name): mixed
    {
        try {
            foreach ($this->reflection?->getParameters() ?? [] as $paramIndex => $reflectionParameter) {
                if ($name !== $reflectionParameter->name) {
                    continue;
                }

                return $reflectionParameter->getDefaultValue();
            }

        } catch (ReflectionException) {
        }

        return null;
    }


    public function getArgumentType(string $name): Type
    {
        if ($this->parameterNames === null && $this->reflection) {
            $this->parameterNames = array_flip(array_map(
                fn ($p) => $p->name,
                $this->reflection->getParameters(),
            ));
        }

        $index = $this->args->findNamedIndex($name) ?? $this->parameterNames[$name] ?? null;

        if ($index !== null) {
            return $this->args->get($index);
        }

        return new UnknownType;
    }


    /**
     * @return array<string, ?Type>
     */
    public function fillTemplateTypesFromParameters(): array
    {
        $templateTypes = $this->getPhpDoc()?->getTemplateTypes() ?? [];
        $phpDocParamTypes = $this->getPhpDoc()?->getParameters() ?? [];

        if ($this->methodName === '__construct') {
            $templateTypes = array_merge($templateTypes, $this->scope->getCurrentPhpClass()?->getPhpDoc()?->getTemplateTypes() ?? []);

            $propNodeVisitor = new ClassConstructorPropertyVisitor($this->scope);

            $this->scope->getCurrentPhpClass()?->traverse($propNodeVisitor);

            $phpDocParamTypes = array_merge($phpDocParamTypes, $propNodeVisitor->promotedProperties);
        }

        foreach ($phpDocParamTypes as $name => $unresolvedType) {
            $docTypeNode = $unresolvedType->typeNode;

            if ($docTypeNode instanceof IdentifierTypeNode) {
                if (array_key_exists($docTypeNode->name, $templateTypes)) {
                    $parsedArgumentType = $this->getArgumentType($name);

                    if (! $parsedArgumentType instanceof UnknownType) {
                        $templateTypes[$docTypeNode->name] = $parsedArgumentType;
                    }
                }

            } else if ($docTypeNode instanceof GenericTypeNode && $docTypeNode->genericTypes) {
                if (in_array($docTypeNode->type->name, ['array', 'iterable', 'list', 'non-empty-array', 'non-empty-list'])) {

                    if (isset($docTypeNode->genericTypes[0], $docTypeNode->genericTypes[1])) {
                        $keyType = $docTypeNode->genericTypes[0];
                        $itemType = $docTypeNode->genericTypes[1];

                    } else {
                        $keyType = null;
                        $itemType = $docTypeNode->genericTypes[0] ?? null;
                    }

                    if ($itemType instanceof IdentifierTypeNode && array_key_exists($itemType->name, $templateTypes)) {
                        $argumentType = $this->getArgumentType($name);

                        if ($argumentType instanceof ArrayType) {
                            $argumentType->convertShapeToTypePair($this->scope->config);

                            $templateTypes[$itemType->name] = $argumentType->itemType;
                        }
                    }

                } else if ($docTypeNode->type->name === 'class-string') {
                    $paramName = $docTypeNode->genericTypes[0]->name ?? null;

                    if ((is_int($paramName) || is_string($paramName)) && array_key_exists($paramName, $templateTypes)) {
                        $parameterValue = $this->getArgumentType($name);

                        if ($parameterValue instanceof ClassStringType) {
                            $templateTypes[$paramName] = new UnresolvedClassType($parameterValue->className, $this->scope);
                        }
                    }
                }
            }
        }

        return $templateTypes;
    }


    public function toOperation(): Operation
    {
        $operation = new Operation;

        $requestBodyType = null;
        $responseBodyType = null;

        $phpDoc = $this->getPhpDoc();

        $phpDocReturnType = null;

        if ($phpDoc) {
            $phpDocResponseTag = $phpDoc->getResponseTag();

            if ($phpDocResponseTag) {
                $responseBodyType = $this->getTypeFromPhpDocTag($phpDocResponseTag);

            } else {
                $phpDocReturnTag = $phpDoc->getReturnTag();

                if ($phpDocReturnTag) {
                    $phpDocReturnType = $this->getTypeFromPhpDocTag($phpDocReturnTag)?->resolve();
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

        $analyzedReturnType = null;

        $bodyAnalysis = $this->analyzeBody(analyzeReturnValue: $responseBodyType === null, isOperationEntrypoint: true);

        $requestBodyType ??= $bodyAnalysis['requestBodyType'];
        $analyzedReturnType = $bodyAnalysis['analyzedReturnType'];

        // Collect existing parameter names to avoid duplicates
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
                                type: $requestBodyType,
                                config: $this->scope->config,
                            ),
                        ],
                    );
                }
            }
        }

        if (! $responseBodyType) {
            $responseBodyType = $this->combineReturnTypes(
                analyzedType: $analyzedReturnType,
                phpDocType: $phpDocReturnType,
            );
        }

        // Create responses from analyzed return type
        if (! ($responseBodyType instanceof UnknownType)) {
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
                    : new UnionType($types)->unwrapType($this->scope->config);

                $contentType = $responseType->getContentType();

                $operation->responses[$httpStatusCode] = new Response(
                    content: [
                        $contentType => new MediaType(
                            type: $responseType,
                            config: $this->scope->config,
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
                        type: $type,
                        config: $this->scope->config,
                    ),
                ],
            );
        }

        return $operation;
    }
}
