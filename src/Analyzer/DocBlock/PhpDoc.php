<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\DocBlock;

use AutoDoc\Analyzer\Narrowing\IsType;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedArrayItemType;
use AutoDoc\DataTypes\UnresolvedArrayKeyType;
use AutoDoc\DataTypes\UnresolvedClassType;
use AutoDoc\DataTypes\UnresolvedPhpDocType;
use AutoDoc\Exceptions\AutoDocException;
use Exception;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;
use Traversable;
use WeakMap;

class PhpDoc
{
    public function __construct(
        public string $docComment,
        public Scope $scope,
    ) {
        $this->node = $this->parse();
    }

    private readonly PhpDocNode $node;

    /**
     * @var array<string, ?Type>
     */
    private array $declaredTemplateTypes;

    /**
     * @var array<string, Type>
     */
    private array $typeAliases;

    /**
     * @var array<string, UnresolvedPhpDocType>
     */
    private array $parameters;

    /**
     * Parsed nodes are shared only while their Config is reachable and must remain read-only.
     *
     * @var ?WeakMap<Config, array<string, PhpDocNode>>
     */
    private static ?WeakMap $parsedNodesByConfig = null;


    private function parse(): PhpDocNode
    {
        self::$parsedNodesByConfig ??= new WeakMap;
        $parsedNodes = self::$parsedNodesByConfig[$this->scope->config] ?? [];

        if (isset($parsedNodes[$this->docComment])) {
            return $parsedNodes[$this->docComment];
        }

        /** @var PhpDocParser|null */
        static $phpDocParser = null;

        /** @var Lexer */
        static $lexer;

        if ($phpDocParser === null) {
            $parserConfig = new ParserConfig([]);
            $lexer = new Lexer($parserConfig);
            $constExprParser = new ConstExprParser($parserConfig);
            $typeParser = new TypeParser($parserConfig, $constExprParser);
            $phpDocParser = new PhpDocParser($parserConfig, $typeParser, $constExprParser);
        }

        $tokens = new TokenIterator($lexer->tokenize($this->docComment));

        $parsedNode = $phpDocParser->parse($tokens);
        $parsedNodes[$this->docComment] = $parsedNode;
        self::$parsedNodesByConfig[$this->scope->config] = $parsedNodes;

        return $parsedNode;
    }


    /**
     * https://phpstan.org/writing-php-code/phpdoc-types
     */
    public function resolveTypeFromNode(TypeNode $node, ?PhpDocBinding $binding = null): ?Type
    {
        $binding ??= $this->bindTemplates($this->scope);

        /**
         * type<genericTypes>
         */
        if ($node instanceof GenericTypeNode) {
            $genericTypeValues = array_map(
                callback: fn (TypeNode $genericTypeNode) => $this->createUnresolvedType($genericTypeNode, binding: $binding),
                array: $node->genericTypes,
            );

            $type = $this->resolveTypeFromIdentifier($node->type->name, $binding, $genericTypeValues);

            /**
             * array<keyType, itemType>
             */
            if ($type instanceof ArrayType) {
                if (isset($genericTypeValues[0], $genericTypeValues[1])) {
                    $type->keyType = $genericTypeValues[0];
                    $type->itemType = $genericTypeValues[1];

                } else {
                    $type->itemType = $genericTypeValues[0];
                }
            }

            /**
             * int<0, 100> / int<min, 50> / int<1, self::LIMIT>
             */
            if ($type instanceof IntegerType && $node->type->name === 'int' && count($node->genericTypes) === 2) {
                $type->minimum = $this->resolveIntegerRangeBound($node->genericTypes[0], $binding);
                $type->maximum = $this->resolveIntegerRangeBound($node->genericTypes[1], $binding);
            }

            return $type;
        }

        /**
         * itemType[]
         */
        if ($node instanceof ArrayTypeNode) {
            return new ArrayType(
                itemType: $this->resolveTypeFromNode($node->type, $binding),
            );
        }

        /**
         * type['key'] / type[offsetType]
         */
        if ($node instanceof OffsetAccessTypeNode) {
            return $this->resolveOffsetAccessType($node, $binding);
        }

        /**
         * array{items}
         */
        if ($node instanceof ArrayShapeNode) {
            $arrayType = new ArrayType;

            foreach ($node->items as $itemNode) {
                $itemType = $this->resolveTypeFromNode($itemNode->valueType, $binding) ?? new UnknownType;

                $itemType->required = $itemNode->optional === false;

                if ($itemNode->keyName) {
                    $arrayType->shape[$this->getUnquotedValue($itemNode->keyName)] = $itemType;

                } else {
                    $arrayType->shape[] = $itemType;
                }
            }

            return $arrayType;
        }

        /**
         * type
         */
        if ($node instanceof IdentifierTypeNode) {
            return $this->resolveTypeFromIdentifier($node->name, $binding);
        }

        /**
         * callable(paramTypes): returnType
         */
        if ($node instanceof CallableTypeNode) {
            return new CallableType(
                declaredReturnType: $this->resolveTypeFromNode($node->returnType, $binding) ?? new UnknownType,
            );
        }

        /**
         * $this
         */
        if ($node instanceof ThisTypeNode) {
            return $this->resolveTypeFromIdentifier('static', $binding);
        }

        /**
         * ?type
         */
        if ($node instanceof NullableTypeNode) {
            $type = new UnionType([
                $this->resolveTypeFromNode($node->type, $binding) ?? new UnknownType,
                new NullType,
            ]);

            return $type->unwrapType($this->scope->config);
        }

        /**
         * 'str' / 420 / 0.5 / CONSTANT / Foo::BAR / Foo::BAR_* / Foo::class
         */
        if ($node instanceof ConstTypeNode) {
            if ($node->constExpr instanceof ConstExprStringNode) {
                return new StringType($node->constExpr->value);
            }

            if ($node->constExpr instanceof ConstExprIntegerNode) {
                return new IntegerType((int) $node->constExpr->value);
            }

            if ($node->constExpr instanceof ConstExprFloatNode) {
                return new FloatType((float) $node->constExpr->value);
            }

            if ($node->constExpr instanceof ConstFetchNode) {
                return $this->resolveTypeFromConstFetch($node->constExpr);
            }

            return null;
        }

        /**
         * type1|type2
         */
        if ($node instanceof UnionTypeNode) {
            $unionType = new UnionType;

            foreach ($node->types as $typeNode) {
                $unionType->types[] = $this->resolveTypeFromNode($typeNode, $binding) ?? new UnknownType;
            }

            $unionType->isEnum = true;

            return $unionType->unwrapType($this->scope->config);
        }

        /**
         * type1&type2
         */
        if ($node instanceof IntersectionTypeNode) {
            $intersectionType = new IntersectionType;

            foreach ($node->types as $typeNode) {
                $intersectionType->types[] = $this->resolveTypeFromNode($typeNode, $binding) ?? new UnknownType;
            }

            return $intersectionType->unwrapType($this->scope->config);
        }

        /**
         * ($param is targetType ? if : else) / (subjectType is targetType ? if : else)
         */
        if ($node instanceof ConditionalTypeForParameterNode) {
            $branchNode = $this->resolveConditionalBranchFromArgument($node, $binding);

            if ($branchNode) {
                return $this->resolveTypeFromNode($branchNode, $binding);
            }
        }

        if ($node instanceof ConditionalTypeForParameterNode || $node instanceof ConditionalTypeNode) {
            $type = new UnionType([
                $this->resolveTypeFromNode($node->if, $binding) ?? new UnknownType,
                $this->resolveTypeFromNode($node->else, $binding) ?? new UnknownType,
            ]);

            return $type->unwrapType($this->scope->config);
        }

        /**
         * object{properties}
         */
        if ($node instanceof ObjectShapeNode) {
            $objectType = new ObjectType;

            foreach ($node->items as $itemNode) {
                $itemType = $this->resolveTypeFromNode($itemNode->valueType, $binding) ?? new UnknownType;

                $itemType->required = $itemNode->optional === false;

                $objectType->properties[$this->getUnquotedValue($itemNode->keyName)] = $itemType;
            }

            return $objectType;
        }

        return null;
    }


    /**
     * @param array<?UnresolvedPhpDocType> $genericTypeValues
     */
    private function resolveTypeFromIdentifier(string $identifier, PhpDocBinding $binding, array $genericTypeValues = []): ?Type
    {
        $type = match ($identifier) {
            'class-string', 'interface-string', 'trait-string', 'enum-string' => new ClassStringType(classTemplateType: $genericTypeValues[0] ?? null),
            'key-of' => isset($genericTypeValues[0]) ? new UnresolvedArrayKeyType($genericTypeValues[0], $this->scope) : null,
            'value-of' => isset($genericTypeValues[0]) ? $this->resolveValueOfType($genericTypeValues[0]) : null,
            default => Type::fromKeyword($identifier),
        };

        if ($type) {
            return $type;
        }

        if ($this->scope->className) {
            $type = $this->scope->getCurrentPhpClass()?->getPhpDoc()?->getTypeAliases()[$identifier] ?? null;

            if ($type) {
                return $type->unwrapType($this->scope->config);
            }

            // The actual context might be within a trait, so we need to check the trait's PHPDoc for type aliases there.
            foreach ($this->scope->getCurrentPhpClass()?->getReflection()->getTraits() ?? [] as $traitReflection) {
                $traitDocComment = $traitReflection->getDocComment();

                if ($traitDocComment) {
                    $traitPhpDoc = new PhpDoc($traitDocComment, $this->scope->createChildScope($traitReflection->getName()));

                    $type = $traitPhpDoc->getTypeAliases()[$identifier] ?? null;

                    if ($type) {
                        return $type->unwrapType($this->scope->config);
                    }
                }
            }
        }

        $templateType = $binding->templateTypes[$identifier] ?? null;

        if ($templateType) {
            // Bound values are shared across every position that names the template.
            $templateType = clone $templateType;

            if ($templateType instanceof UnresolvedClassType) {
                $templateType->templateTypeValues = $genericTypeValues;
            }

            return $templateType->unwrapType($this->scope->config);
        }

        $className = $this->scope->getResolvedClassName($identifier);

        if ($className === Traversable::class) {
            return new ArrayType;
        }

        if ($className) {
            $phpClass = $this->scope->getPhpClassInDeeperScope($className);

            if ($phpClass->exists()) {
                $phpClass->setTemplateTypeValues($genericTypeValues);

                return $phpClass->resolveType();
            }
        }

        return $this->resolveTypeFromConstantName($identifier);
    }


    private function resolveConditionalBranchFromArgument(ConditionalTypeForParameterNode $node, PhpDocBinding $binding): ?TypeNode
    {
        if (! $binding->argumentTypeResolver) {
            return null;
        }

        $targetType = $this->resolveTypeFromNode($node->targetType, $binding);

        if (! $targetType || $targetType instanceof UnknownType) {
            return null;
        }

        $argumentType = ($binding->argumentTypeResolver)(substr($node->parameterName, 1))->unwrapType($this->scope->config);

        if ($argumentType instanceof UnknownType || $argumentType instanceof NeverType) {
            return null;
        }

        if ($argumentType->isSubTypeOf($targetType, $this->scope->config)) {
            $conditionHolds = true;

        } else if (new IsType($targetType)->apply($argumentType, $this->scope) instanceof NeverType) {
            $conditionHolds = false;

        } else {
            return null;
        }

        return $conditionHolds !== $node->negated ? $node->if : $node->else;
    }


    /**
     * `value-of<T>` is the item type of an array, or the backing type of an enum.
     */
    private function resolveValueOfType(UnresolvedPhpDocType $genericTypeValue): Type
    {
        $identifier = $genericTypeValue->getIdentifier();
        $className = $identifier === null ? null : $this->scope->getResolvedClassName($identifier);

        if ($className !== null) {
            $phpClass = $this->scope->getPhpClassInDeeperScope($className);

            if ($phpClass->exists() && $phpClass->getReflection()->isEnum()) {
                $enumType = $phpClass->resolveType();

                return $enumType->typeToDisplay ?? $enumType;
            }
        }

        return new UnresolvedArrayItemType($genericTypeValue, $this->scope);
    }


    /**
     * A literal offset picks that key; any other offset yields the union of all item types.
     */
    private function resolveOffsetAccessType(OffsetAccessTypeNode $node, PhpDocBinding $binding): ?Type
    {
        $type = $this->resolveTypeFromNode($node->type, $binding)?->unwrapType($this->scope->config);

        if ($type === null) {
            return null;
        }

        $offsetType = $this->resolveTypeFromNode($node->offset, $binding);

        if (($offsetType instanceof StringType || $offsetType instanceof IntegerType)
            && (is_string($offsetType->value) || is_int($offsetType->value))
        ) {
            return $this->scope->getTypeAtKey($type, $offsetType->value);
        }

        return new UnresolvedArrayItemType($type, $this->scope)->resolve();
    }


    /**
     * A bound is either an integer literal, a constant that holds one,
     * or the `min`/`max` keyword, which leaves that end unbounded.
     */
    private function resolveIntegerRangeBound(TypeNode $node, PhpDocBinding $binding): ?int
    {
        $boundType = $this->resolveTypeFromNode($node, $binding);

        return $boundType instanceof IntegerType && is_int($boundType->value)
            ? $boundType->value
            : null;
    }


    private function resolveTypeFromConstantName(string $name): ?Type
    {
        $constantName = $this->scope->getResolvedConstantName($name);

        if (! defined($constantName)) {
            return null;
        }

        return Type::fromValue(constant($constantName));
    }


    private function resolveTypeFromConstFetch(ConstFetchNode $node): ?Type
    {
        if ($node->className === '') {
            return $this->resolveTypeFromConstantName($node->name);
        }

        $className = $this->scope->getResolvedClassName($node->className);

        if (! $className) {
            return null;
        }

        if ($node->name === 'class') {
            return new ClassStringType(className: $className);
        }

        $phpClass = $this->scope->getPhpClassInDeeperScope($className);

        if (! $phpClass->exists()) {
            return null;
        }

        if (str_contains($node->name, '*')) {
            return $phpClass->resolveConstantTypeByWildcard($node->name);
        }

        return $phpClass->resolveConstantType($node->name);
    }


    public function resolveVarType(?string $name, ?PhpDocBinding $binding = null): Type
    {
        foreach ($this->getVarTags($binding) as $var) {
            [$varName, $varType] = $var;

            if ($name && $varName && $name !== $varName) {
                continue;
            }

            return $varType->resolve();
        }

        return new UnknownType;
    }


    public function resolvePropertyType(string $name, ?PhpDocBinding $binding = null): Type
    {
        if ($this->node->getVarTagValues()) {
            return $this->resolveVarType($name, $binding);
        }

        $phpClass = $this->scope->getCurrentPhpClass();

        return $phpClass?->resolvePropertyFromParentDocComments($name, $binding) ?? new UnknownType;
    }


    /**
     * Returns `@var` types and names (if specified).
     *
     * @return array{?string, UnresolvedPhpDocType}[]
     */
    public function getVarTags(?PhpDocBinding $binding = null): array
    {
        $tags = [];

        foreach ($this->node->getVarTagValues() as $varTag) {
            $name = $varTag->variableName ? substr($varTag->variableName, 1) : null;

            $type = $this->createUnresolvedType($varTag->type, $varTag->description ?: null, $binding);

            $tags[] = [$name, $type];
        }

        return $tags;
    }

    /**
     * Returns `@property` names and types.
     *
     * @return array{?string, UnresolvedPhpDocType}[]
     */
    public function getPropertyTags(?PhpDocBinding $binding = null): array
    {
        $tags = [];

        foreach ($this->node->getPropertyTagValues() as $tag) {
            $name = $tag->propertyName ? substr($tag->propertyName, 1) : null;

            $type = $this->createUnresolvedType($tag->type, $tag->description ?: null, $binding);

            $tags[] = [$name, $type];
        }

        return $tags;
    }

    /**
     * Returns `@property-description` tags.
     *
     * @return array{string, string}[]
     */
    public function getPropertyDescriptionTags(): array
    {
        $tags = [];

        foreach ($this->node->getTagsByName('@property-description') as $tag) {
            if ($tag->value instanceof GenericTagValueNode) {
                if (preg_match('/^\s*(\S+)\s*(.*)$/s', $tag->value->value, $matches)) {
                    $propertyName = ltrim($matches[1], '$');
                    $description = $matches[2];

                    $tags[] = [$propertyName, $description];
                }
            }
        }

        return $tags;
    }

    /**
     * Returns `@property-example` tags.
     *
     * @return array{string, string}[]
     */
    public function getPropertyExampleTags(): array
    {
        $tags = [];

        foreach ($this->node->getTagsByName('@property-example') as $tag) {
            if ($tag->value instanceof GenericTagValueNode) {
                if (preg_match('/^\s*(\S+)\s*(.*)$/s', $tag->value->value, $matches)) {
                    $propertyName = ltrim($matches[1], '$');
                    $example = $matches[2];

                    $tags[] = [$propertyName, $example];
                }
            }
        }

        return $tags;
    }

    /**
     * @return array{description: string}[]
     */
    public function getDeprecatedTags(): array
    {
        $tags = [];

        foreach ($this->node->getDeprecatedTagValues() as $deprecatedTag) {
            $tags[] = [
                'description' => $deprecatedTag->description,
            ];
        }

        return $tags;
    }


    public function getReturnTag(): ?ReturnTagValueNode
    {
        return $this->node->getReturnTagValues()[0] ?? null;
    }

    public function getResponseTag(): ?PhpDocTagValueNode
    {
        return array_column($this->node->getTagsByName('@response'), 'value')[0] ?? null;
    }


    public function getTypeFromPhpDocTag(PhpDocTagValueNode $tagValueNode, ?PhpDocBinding $binding = null): ?UnresolvedPhpDocType
    {
        $tagBinding = new PhpDocBinding(
            templateTypes: [
                ...($this->scope->getCurrentPhpClass()?->getPhpDoc()?->getTemplateTypes() ?? []),
                ...$this->bindTemplates($this->scope)->templateTypes,
                ...($binding->templateTypes ?? []),
            ],
            argumentTypeResolver: $binding?->argumentTypeResolver,
        );

        if ($tagValueNode instanceof ReturnTagValueNode) {
            return $this->createUnresolvedType(
                typeNode: $tagValueNode->type,
                description: $tagValueNode->description,
                binding: $tagBinding,
            );

        } else if ($tagValueNode instanceof GenericTagValueNode) {
            return $this->createUnresolvedType(
                typeNode: $this->createTypeNode($tagValueNode->value),
                binding: $tagBinding,
            );
        }

        return null;
    }


    /**
     * @return array{
     *     body: ?Type,
     *     cookie: array<string, Type>,
     *     header: array<string, Type>,
     *     path: array<string, Type>,
     *     query: array<string, Type>,
     * }
     */
    public function getRequestParams(): array
    {
        $tagTypes = [
            '@request-query'     => 'query',
            '@request-header'    => 'header',
            '@request-cookie'    => 'cookie',
            '@request-url-param' => 'path',
        ];

        $requestParams = [];

        foreach ($tagTypes as $tagName => $paramLocation) {
            foreach ($this->node->getTagsByName($tagName) as $tag) {
                $param = new RequestParamTag($tag, $this)->resolve();

                if (! $param) {
                    continue;
                }

                [$paramName, $paramType] = $param;

                $requestParams[$paramLocation][$paramName] = $paramType;
            }
        }

        $requestBodyType = null;

        foreach (['@request-body', '@request'] as $tagName) {
            $tags = $this->node->getTagsByName($tagName);

            if ($tags) {
                $requestBodyTag = reset($tags);

                if ($requestBodyTag->value instanceof GenericTagValueNode) {
                    $requestBodyType = $this->createUnresolvedType($this->createTypeNode($requestBodyTag->value->value));

                    break;
                }
            }
        }

        return [
            'body'   => $requestBodyType,
            'cookie' => $requestParams['cookie'] ?? [],
            'header' => $requestParams['header'] ?? [],
            'path'   => $requestParams['path'] ?? [],
            'query'  => $requestParams['query'] ?? [],
        ];
    }


    /**
     * Returns `@example` values.
     *
     * @return string[]
     */
    public function getExampleValues(): array
    {
        $tags = [];

        foreach ($this->node->getTagsByName('@example') as $tag) {
            $tags[] = (string) $tag->value;
        }

        return $tags;
    }

    /**
     * Declared `@template` names mapped to their bound types.
     *
     * @return array<string, ?Type>
     */
    public function getTemplateTypes(): array
    {
        if (isset($this->declaredTemplateTypes)) {
            return $this->declaredTemplateTypes;
        }

        $this->declaredTemplateTypes = [];

        $tags = [
            ...$this->node->getTemplateTagValues('@template'),
            ...$this->node->getTemplateTagValues('@template-covariant'),
            ...$this->node->getTemplateTagValues('@template-contravariant'),
        ];

        foreach ($tags as $templateTag) {
            $boundType = $templateTag->bound ? $this->createUnresolvedType($templateTag->bound) : null;

            $this->declaredTemplateTypes[$templateTag->name] = $boundType;
        }

        return $this->declaredTemplateTypes;
    }


    /**
     * This doc's declared templates, overridden by the class template values bound in `$scope`.
     */
    public function bindTemplates(Scope $scope): PhpDocBinding
    {
        return new PhpDocBinding([
            ...$this->getTemplateTypes(),
            ...$scope->constructorTemplateTypes,
        ]);
    }


    /**
     * @return null|object{
     *     className: class-string,
     *     genericTypes: UnresolvedPhpDocType[],
     * }
     */
    public function getExtendsTag(?PhpDocBinding $binding = null): ?object
    {
        foreach ($this->node->getExtendsTagValues() as $extendsTag) {
            try {
                $parentClassName = $this->scope->getResolvedClassName($extendsTag->type->type->name);

                if (! $parentClassName) {
                    throw new Exception('Unable to resolve class name');
                }

                return (object) [
                    'className' => $parentClassName,
                    'genericTypes' => array_map(
                        callback: fn (TypeNode $genericTypeNode) => $this->createUnresolvedType($genericTypeNode, binding: $binding),
                        array: $extendsTag->type->genericTypes,
                    ),
                ];

            } catch (Throwable $exception) {
                if ($this->scope->isDebugModeEnabled()) {
                    throw new AutoDocException('Error resolving "@extends ' . ((string) $extendsTag->type) . '": ', $exception);
                }
            }
        }

        return null;
    }


    /**
     * @return array<string, object{
     *     returnType: ?Type,
     * }>
     */
    public function getMethodTags(): array
    {
        $methodTags = [];

        foreach ($this->node->getMethodTagValues() as $methodTag) {
            try {
                $methodTags[$methodTag->methodName] = (object) [
                    'returnType' => $methodTag->returnType ? $this->createUnresolvedType($methodTag->returnType) : null,
                ];

            } catch (Throwable $exception) {
                if ($this->scope->isDebugModeEnabled()) {
                    throw new AutoDocException('Error resolving "@method ' . ((string) $methodTag) . '": ', $exception);
                }
            }
        }

        return $methodTags;
    }


    /**
     * @return null|object{
     *     className: class-string,
     *     genericTypes: UnresolvedPhpDocType[],
     * }
     */
    public function getMixinTag(): ?object
    {
        foreach ($this->node->getMixinTagValues() as $mixinTag) {
            try {
                if ($mixinTag->type instanceof IdentifierTypeNode) {
                    $mixinClassName = $this->scope->getResolvedClassName($mixinTag->type->name);

                    if (! $mixinClassName) {
                        throw new Exception('Unable to resolve class name');
                    }

                    return (object) [
                        'className' => $mixinClassName,
                        'genericTypes' => [],
                    ];
                }

            } catch (Throwable $exception) {
                if ($this->scope->isDebugModeEnabled()) {
                    throw new AutoDocException('Error resolving "@mixin ' . ((string) $mixinTag->type) . '": ', $exception);
                }
            }
        }

        return null;
    }


    public function getAutodocTag(): ?PhpDocTagValueNode
    {
        return array_column($this->node->getTagsByName('@autodoc'), 'value')[0] ?? null;
    }

    public function getAutodocIgnoreTag(): ?PhpDocTagValueNode
    {
        return array_column($this->node->getTagsByName('@autodoc-ignore'), 'value')[0] ?? null;
    }


    /**
     * @return array<string, Type>
     */
    public function getTypeAliases(): array
    {
        if (isset($this->typeAliases)) {
            return $this->typeAliases;
        }

        $this->typeAliases = [];

        foreach ($this->node->getTypeAliasTagValues() as $tag) {
            $this->typeAliases[$tag->alias] = $this->createUnresolvedType($tag->type);
        }

        foreach ($this->node->getTypeAliasImportTagValues() as $importTag) {
            try {
                $className = $this->scope->getResolvedClassName($importTag->importedFrom->name);

                if (! $className) {
                    throw new Exception('Class "' . $importTag->importedFrom->name . '" not found');
                }

                $importedFromPhpClass = $this->scope->getPhpClassInDeeperScope($className);
                $importedFromPhpDoc = $importedFromPhpClass->getPhpDoc();

                if (! $importedFromPhpDoc) {
                    throw new Exception('Doc comment not found in "' . $className . '"');
                }

                $typeAlias = $importTag->importedAs ?? $importTag->importedAlias;
                $typeAliasDefinitionTag = $importedFromPhpDoc->getTypeAliases()[$importTag->importedAlias] ?? null;

                if (! $typeAliasDefinitionTag) {
                    throw new Exception('Type alias definition not found in "' . $className . '"');
                }

                $this->typeAliases[$typeAlias] = $typeAliasDefinitionTag;

            } catch (Throwable $exception) {
                if ($this->scope->isDebugModeEnabled()) {
                    throw new AutoDocException('Error importing type alias "' . $importTag->importedFrom->name . '": ', $exception);
                }
            }
        }

        return $this->typeAliases;
    }

    /**
     * @return array<string, UnresolvedPhpDocType>
     */
    public function getParameters(): array
    {
        if (isset($this->parameters)) {
            return $this->parameters;
        }

        $this->parameters = [];

        foreach ($this->node->getParamTagValues() as $tag) {
            $this->parameters[substr($tag->parameterName, 1)] = $this->createUnresolvedType($tag->type, $tag->description);
        }

        return $this->parameters;
    }


    public function getText(): string
    {
        $lines = [];

        foreach ($this->node->children as $node) {
            if ($node instanceof PhpDocTextNode) {
                $lines[] = $node->text;
            }
        }

        return trim(implode("\n", $lines));
    }


    /**
     * @return array{?string, ?string}
     */
    public function getSummaryAndDescription(): array
    {
        $summaryAndDescription = explode("\n\n", str_replace("\r\n", "\n", $this->getText()), 2);

        $summary = $summaryAndDescription[0];
        $description = $summaryAndDescription[1] ?? '';

        return [
            $summary === '' ? null : $summary,
            $description === '' ? null : $description,
        ];
    }


    public function createTypeNode(string $phpDocType): TypeNode
    {
        /** @var TypeParser|null */
        static $typeParser = null;

        /** @var Lexer */
        static $lexer;

        if ($typeParser === null) {
            $parserConfig = new ParserConfig([]);
            $lexer = new Lexer($parserConfig);
            $typeParser = new TypeParser($parserConfig, new ConstExprParser($parserConfig));
        }

        $tokens = new TokenIterator($lexer->tokenize($phpDocType));

        try {
            return $typeParser->parse($tokens);

        } catch (Throwable $exception) {
            throw new AutoDocException('Error parsing type "' . $phpDocType . '": ', $exception);
        }
    }


    public function createUnresolvedType(TypeNode $typeNode, ?string $description = null, ?PhpDocBinding $binding = null): UnresolvedPhpDocType
    {
        return new UnresolvedPhpDocType(
            typeNode: $typeNode,
            phpDoc: $this,
            description: $description,
            binding: $binding,
        );
    }


    private function getUnquotedValue(ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode $node): string
    {
        if ($node instanceof ConstExprStringNode) {
            return $node->value;
        }

        return (string) $node;
    }
}
