<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedClassType;
use AutoDoc\DataTypes\UnresolvedPhpDocType;
use AutoDoc\DataTypes\VoidType;
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
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

class PhpDoc
{
    public function __construct(
        public string $docComment,
        public Scope $scope,
    ) {
        $this->node = $this->parse();
    }

    private PhpDocNode $node;

    /**
     * @var array<string, ?Type>
     */
    public array $templateTypes;

    /**
     * @var array<string, Type>
     */
    private array $typeAliases;

    /**
     * @var array<string, UnresolvedPhpDocType>
     */
    private array $parameters;


    private function parse(): PhpDocNode
    {
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

        return $phpDocParser->parse($tokens);
    }


    /**
     * https://phpstan.org/writing-php-code/phpdoc-types
     */
    public function resolveTypeFromNode(TypeNode $node): ?Type
    {
        /**
         * type<genericTypes>
         */
        if ($node instanceof GenericTypeNode) {
            $genericTypeValues = array_map($this->createUnresolvedType(...), $node->genericTypes);

            $type = $this->resolveTypeFromIdentifier($node->type->name, $genericTypeValues);

            /**
             * array<keyType, itemType>
             */
            if ($type instanceof ArrayType) {
                if (isset($genericTypeValues[0], $genericTypeValues[1])) {
                    $type->keyType = $genericTypeValues[0];
                    $type->itemType = $genericTypeValues[1];

                    if ($type->itemType->typeNode instanceof IdentifierTypeNode
                        && isset($this->scope->constructorTemplateTypes[$type->itemType->typeNode->name])
                    ) {
                        $type->itemType = $this->scope->constructorTemplateTypes[$type->itemType->typeNode->name];
                    }

                } else {
                    $type->itemType = $genericTypeValues[0];
                }
            }

            return $type;
        }

        /**
         * itemType[]
         */
        if ($node instanceof ArrayTypeNode) {
            return new ArrayType(
                itemType: $this->resolveTypeFromNode($node->type),
            );
        }

        /**
         * array{items}
         */
        if ($node instanceof ArrayShapeNode) {
            $arrayType = new ArrayType;

            foreach ($node->items as $itemNode) {
                $itemType = $this->resolveTypeFromNode($itemNode->valueType) ?? new UnknownType;

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
            return $this->resolveTypeFromIdentifier($node->name);
        }

        /**
         * ?type
         */
        if ($node instanceof NullableTypeNode) {
            $type = new UnionType([
                $this->resolveTypeFromIdentifier((string) $node->type) ?? new UnknownType,
                new NullType,
            ]);

            return $type->unwrapType($this->scope->config);
        }

        /**
         * 'str' / 420 / 0.5
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

            return null;
        }

        /**
         * type1|type2
         */
        if ($node instanceof UnionTypeNode) {
            $unionType = new UnionType;

            foreach ($node->types as $typeNode) {
                $unionType->types[] = $this->resolveTypeFromNode($typeNode) ?? new UnknownType;
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
                $intersectionType->types[] = $this->resolveTypeFromNode($typeNode) ?? new UnknownType;
            }

            return $intersectionType->unwrapType($this->scope->config);
        }

        /**
         * (if $param is $node->targetType ? $node->if : $node->else)
         */
        if ($node instanceof ConditionalTypeForParameterNode) {
            $type = new UnionType([
                $this->resolveTypeFromNode($node->if) ?? new UnknownType,
                $this->resolveTypeFromNode($node->else) ?? new UnknownType,
            ]);

            return $type->unwrapType($this->scope->config);
        }

        /**
         * object{properties}
         */
        if ($node instanceof ObjectShapeNode) {
            $objectType = new ObjectType;

            foreach ($node->items as $itemNode) {
                $itemType = $this->resolveTypeFromNode($itemNode->valueType) ?? new UnknownType;

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
    private function resolveTypeFromIdentifier(string $identifier, array $genericTypeValues = []): ?Type
    {
        $type = match ($identifier) {
            'int', 'integer', 'non-zero-int' => new IntegerType,
            'positive-int' => new IntegerType(minimum: 1),
            'negative-int' => new IntegerType(maximum: -1),
            'non-positive-int' => new IntegerType(maximum: 0),
            'non-negative-int' => new IntegerType(minimum: 0),
            'float', 'double' => new FloatType,
            'string', 'lowercase-string', 'uppercase-string', 'literal-string',
            'non-empty-lowercase-string', 'non-empty-uppercase-string', 'non-empty-literal-string',
            'non-empty-string', 'non-falsy-string', 'truthy-string' => new StringType,
            'class-string', 'interface-string', 'trait-string', 'enum-string' => new ClassStringType(classTemplateType: $genericTypeValues[0] ?? null),
            'numeric-string' => new NumberType(isString: true),
            'true' => new BoolType(true),
            'false' => new BoolType(false),
            'bool', 'boolean' => new BoolType,
            'array', 'list', 'iterable' => new ArrayType,
            'non-empty-array', 'non-empty-list' => new ArrayType(minItems: 1),
            'associative-array' => new ObjectType,
            'object' => new ObjectType,
            'scalar' => new UnionType([
                new IntegerType,
                new FloatType,
                new StringType,
                new BoolType,
            ]),
            'numeric' => new UnionType([
                new IntegerType,
                new FloatType,
                new NumberType(isString: false),
            ]),
            'null' => new NullType,
            'void' => new VoidType,
            default => null,
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

        $templateTypes = $this->getTemplateTypes();

        if (array_key_exists($identifier, $templateTypes)) {
            // Identifier is a `@template` type.

            if ($templateTypes[$identifier]) {

                if ($templateTypes[$identifier] instanceof UnresolvedClassType) {
                    $templateTypes[$identifier]->templateTypeValues = $genericTypeValues;
                }

                return $templateTypes[$identifier]->unwrapType($this->scope->config);
            }
        }

        $className = $this->scope->getResolvedClassName($identifier);

        if (! $className) {
            return null;
        }

        $phpClass = $this->scope->getPhpClassInDeeperScope($className);

        if (! $phpClass->exists()) {
            return null;
        }

        $phpClass->setTemplateTypeValues($genericTypeValues);

        return $phpClass->resolveType();
    }


    public function resolveVarType(?string $name): Type
    {
        foreach ($this->getVarTags() as $var) {
            [$varName, $varType] = $var;

            if ($name && $varName && $name !== $varName) {
                continue;
            }

            $identifier = $varType->getIdentifier();

            if ($identifier && isset($this->scope->constructorTemplateTypes[$identifier])) {
                return $this->scope->constructorTemplateTypes[$identifier]->unwrapType();
            }

            return $varType->resolve();
        }

        return new UnknownType;
    }


    public function resolvePropertyType(string $name): Type
    {
        $varTags = $this->getVarTags();

        if ($varTags) {
            return $this->resolveVarType($name);
        }

        $phpClass = $this->scope->getCurrentPhpClass();

        return $phpClass?->resolvePropertyFromParentDocComments($name) ?? new UnknownType;
    }


    /**
     * Returns `@var` types and names (if specified).
     *
     * @return array{?string, UnresolvedPhpDocType}[]
     */
    public function getVarTags(): array
    {
        $tags = [];

        foreach ($this->node->getVarTagValues() as $varTag) {
            $name = $varTag->variableName ? substr($varTag->variableName, 1) : null;

            $type = $this->createUnresolvedType($varTag->type, $varTag->description ?: null);

            $tags[] = [$name, $type];
        }

        return $tags;
    }

    /**
     * Returns `@property` names and types.
     *
     * @return array{?string, UnresolvedPhpDocType}[]
     */
    public function getPropertyTags(): array
    {
        $tags = [];

        foreach ($this->node->getPropertyTagValues() as $tag) {
            $name = $tag->propertyName ? substr($tag->propertyName, 1) : null;

            $type = $this->createUnresolvedType($tag->type, $tag->description ?: null);

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


    /**
     * @param array<string, ?Type> $templateTypes
     */
    public function getTypeFromPhpDocTag(PhpDocTagValueNode $tagValueNode, array $templateTypes = []): ?UnresolvedPhpDocType
    {
        $this->templateTypes = array_merge(
            $this->getTemplateTypes(),
            $this->scope->getCurrentPhpClass()?->getPhpDoc()?->getTemplateTypes() ?? [],
            $templateTypes,
        );

        if ($tagValueNode instanceof ReturnTagValueNode) {
            return $this->createUnresolvedType(
                typeNode: $tagValueNode->type,
                description: $tagValueNode->description,
            );

        } else if ($tagValueNode instanceof GenericTagValueNode) {
            return $this->createUnresolvedType($this->createTypeNode($tagValueNode->value));
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
                $param = (new PhpDocRequestParamTag($tag, $this))->resolve();

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
     * @return array<string, ?Type>
     */
    public function getTemplateTypes(): array
    {
        if (isset($this->templateTypes)) {
            return $this->templateTypes;
        }

        $this->templateTypes = [];

        $tags = [
            ...$this->node->getTemplateTagValues('@template'),
            ...$this->node->getTemplateTagValues('@template-covariant'),
            ...$this->node->getTemplateTagValues('@template-contravariant'),
        ];

        foreach ($tags as $templateTag) {
            $boundType = $templateTag->bound ? $this->createUnresolvedType($templateTag->bound) : null;

            $this->templateTypes[$templateTag->name] = $boundType;
        }

        return $this->templateTypes;
    }


    /**
     * @return null|object{
     *     className: class-string,
     *     genericTypes: UnresolvedPhpDocType[],
     * }
     */
    public function getExtendsTag(): ?object
    {
        foreach ($this->node->getExtendsTagValues() as $extendsTag) {
            try {
                $parentClassName = $this->scope->getResolvedClassName($extendsTag->type->type->name);

                if (! $parentClassName) {
                    throw new Exception('Unable to resolve class name');
                }

                return (object) [
                    'className' => $parentClassName,
                    'genericTypes' => array_map($this->createUnresolvedType(...), $extendsTag->type->genericTypes),
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
     * @return array{string, string}
     */
    public function getSummaryAndDescription(): array
    {
        $summaryAndDescription = explode("\n\n", str_replace("\r\n", "\n", $this->getText()), 2);

        return [
            $summaryAndDescription[0],
            $summaryAndDescription[1] ?? '',
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


    public function createUnresolvedType(TypeNode $typeNode, ?string $description = null): UnresolvedPhpDocType
    {
        return new UnresolvedPhpDocType($typeNode, $this, $description);
    }


    private function getUnquotedValue(ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode $node): string
    {
        if ($node instanceof ConstExprStringNode) {
            return $node->value;
        }

        return (string) $node;
    }
}
