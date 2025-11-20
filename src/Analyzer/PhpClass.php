<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedType;
use DateTimeInterface;
use DOMNode;
use Exception;
use JsonSerializable;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use SplFixedArray;
use Stringable;

/**
 * @template-covariant TClass of object
 */
class PhpClass
{
    public function __construct(
        /**
         * @var class-string<TClass>
         */
        public readonly string $className,
        public Scope $scope,
    ) {}

    public ?NameResolver $nameResolver = null;

    public ?Type $typeToDisplay = null;

    public bool $isFinalResponse = false;

    /**
     * @var array<string, Type>
     */
    private array $publicProperties;

    /**
     * @var array<string, Type>
     */
    private array $privateAndProtectedProperties;

    /**
     * @var ReflectionClass<TClass>
     */
    private ReflectionClass $classReflection;

    private ?PhpDoc $docComment = null;

    /**
     * @var Node\Stmt[]
     */
    private array $ast;


    public function resolveType(bool $useExtensions = true): ObjectType
    {
        $objectType = new ObjectType(className: $this->className);

        if ($useExtensions) {
            $returnType = $this->scope->getReturnTypeFromExtensions($this);

            if ($returnType !== null) {
                $returnType = $returnType->unwrapType($this->scope->config);

                if ($returnType instanceof ObjectType) {
                    return $returnType;
                }

                $objectType->typeToDisplay = $returnType;

                return $objectType;
            }
        }

        if ($this->typeToDisplay) {
            $objectType->typeToDisplay = $this->typeToDisplay->unwrapType($this->scope->config);

        } else if ($this->getReflection()->isEnum()) {
            $objectType->typeToDisplay = (new PhpEnum($this))->resolveType();

        } else if (is_a($this->className, DateTimeInterface::class, true)) {
            $objectType->typeToDisplay = new StringType(format: 'date-time');

        } else if ($this->getReflection()->isSubclassOf(JsonSerializable::class)) {
            $toArrayMethod = $this->getMethod('jsonSerialize');

            if ($toArrayMethod->exists()) {
                $phpDocResultType = $toArrayMethod->getReturnType(doNotAnalyzeBody: true);

                if ($phpDocResultType instanceof ArrayType && $phpDocResultType->shape) {
                    $objectType->typeToDisplay = $phpDocResultType->unwrapType($this->scope->config);

                } else {
                    $analyzedResultType = $toArrayMethod->getReturnType(usePhpDocIfAvailable: false);

                    if (! ($analyzedResultType instanceof UnknownType)) {
                        $objectType->typeToDisplay = $analyzedResultType->unwrapType($this->scope->config);
                    }
                }
            }

        } else if (is_a($this->className, Stringable::class, true)) {
            $objectType->typeToDisplay = new StringType;

        } else if (is_a($this->className, DOMNode::class, true)) {
            return $objectType;

        } else if (is_a($this->className, SplFixedArray::class, true)) {
            $objectType->typeToDisplay = new ArrayType;
        }

        if ($this->scope->depth > $this->scope->config->data['max_depth']) {
            return $objectType;
        }

        if (! ($this->scope->config->data['classes']['remove_description'] ?? false)) {
            $classPhpDoc = $this->getPhpDoc();

            if ($classPhpDoc) {
                $objectType->description = $classPhpDoc->getText();
            }
        }

        $objectType->properties = $this->getProperties();

        $objectType->constructorArgs = $this->scope->constructorArgs;

        return $objectType;
    }


    /**
     * @return ReflectionClass<TClass>
     */
    public function getReflection(): ReflectionClass
    {
        if (isset($this->classReflection)) {
            return $this->classReflection;
        }

        $this->classReflection = new ReflectionClass($this->className);

        return $this->classReflection;
    }


    /**
     * @return array<string, Type>
     */
    private function getProperties(bool $onlyPublic = true): array
    {
        if ($this->scopeAllowsUsingCache()) {
            if ($onlyPublic) {
                if (isset($this->publicProperties)) {
                    return $this->publicProperties;
                }

            } else if (isset($this->publicProperties, $this->privateAndProtectedProperties)) {
                return array_merge($this->publicProperties, $this->privateAndProtectedProperties);
            }
        }

        $publicProperties = [];
        $privateAndProtectedProperties = [];

        foreach ($this->getReflection()->getProperties() as $propertyReflection) {
            $isPublic = $propertyReflection->isPublic();

            if ($onlyPublic && !$isPublic) {
                continue;
            }

            $propertyName = $propertyReflection->getName();
            $propertyPhpDoc = $this->getPropertyDocComment($propertyReflection);
            $propertyType = new UnknownType;

            if ($propertyPhpDoc && $propertyReflection->getDeclaringClass()->name === $this->className) {
                $propertyType = $propertyPhpDoc->resolvePropertyType($propertyName);

            } else {
                $propertyType = $this->resolvePropertyFromParentDocComments($propertyName) ?? $propertyType;
            }

            if ($propertyType instanceof UnknownType) {
                $propertyReflectionType = $propertyReflection->getType();

                if ($propertyReflectionType) {
                    $propertyType = Type::resolveFromReflection($propertyReflectionType, $this->scope);
                }
            }

            $propertyType->required = true;

            if ($propertyPhpDoc) {
                $propertyType->description = $propertyPhpDoc->getText();
                $propertyType->examples = $propertyPhpDoc->getExampleValues() ?: null;
            }

            if ($isPublic) {
                $publicProperties[$propertyName] = $propertyType;

            } else {
                $privateAndProtectedProperties[$propertyName] = $propertyType;
            }
        }

        if ($onlyPublic) {
            $publicProperties = $this->handlePhpDocPropertyTags($publicProperties);

        } else {
            $publicProperties = $this->handlePhpDocPropertyTags($publicProperties);
            $privateAndProtectedProperties = $this->handlePhpDocPropertyTags($privateAndProtectedProperties);
        }

        if ($this->scopeAllowsUsingCache()) {
            if ($onlyPublic) {
                $this->publicProperties = $publicProperties;

            } else {
                $this->publicProperties = $publicProperties;
                $this->privateAndProtectedProperties = $privateAndProtectedProperties;
            }
        }

        return array_merge($publicProperties, $privateAndProtectedProperties);
    }


    public function getProperty(string $name, bool $onlyPublic = true): ?Type
    {
        return $this->getProperties($onlyPublic)[$name] ?? null;
    }


    public function hasLoadedProperties(): bool
    {
        return isset($this->publicProperties);
    }

    /**
     * @param array<string, Type> $properties
     * @return array<string, Type>
     */
    public function handlePhpDocPropertyTags(array $properties): array
    {
        foreach ($this->getPhpDoc()?->getPropertyTags() ?? [] as $propertyTag) {
            [$propertyName, $propertyType] = $propertyTag;

            $properties[$propertyName] = $propertyType;
        }

        foreach ($this->getPhpDoc()?->getPropertyDescriptionTags() ?? [] as $tag) {
            [$propertyName, $propertyDescription] = $tag;

            if (isset($properties[$propertyName])) {
                $properties[$propertyName]->description = $properties[$propertyName]->description ?: $propertyDescription;
            }
        }

        foreach ($this->getPhpDoc()?->getPropertyExampleTags() ?? [] as $tag) {
            [$propertyName, $propertyExample] = $tag;

            if (isset($properties[$propertyName])) {
                if ($properties[$propertyName]->examples) {
                    $properties[$propertyName]->examples[] = $propertyExample;

                } else {
                    $properties[$propertyName]->examples = [$propertyExample];
                }
            }
        }

        return $properties;
    }


    public function scopeAllowsUsingCache(): bool
    {
        return ! $this->scope->constructorTemplateTypes;
    }


    public function resolveConstantType(string $name): Type
    {
        if ($this->getReflection()->isEnum()) {
            return (new PhpEnum($this))->resolveType();
        }

        $classConstants = $this->getReflection()->getConstants();

        if (! array_key_exists($name, $classConstants)) {
            return new UnknownType;
        }

        $value = $classConstants[$name];

        if (is_string($value)) {
            return new StringType($value);
        }

        if (is_int($value)) {
            return new IntegerType($value);
        }

        if (is_float($value)) {
            return new FloatType($value);
        }

        if (is_array($value)) {
            return new ArrayType;
        }

        if (is_bool($value)) {
            return new BoolType($value);
        }

        if ($value === null) {
            return new NullType;
        }

        return new UnknownType;
    }


    public function resolvePropertyFromDocComments(string $propertyName): ?Type
    {
        try {
            $propertyReflection = $this->getReflection()->getProperty($propertyName);

        } catch (ReflectionException) {
            return null;
        }

        $propertyDocComment = $propertyReflection->getDocComment();

        if (! $propertyDocComment) {
            return null;
        }

        $propertyPhpDoc = new PhpDoc($propertyDocComment, $this->scope);

        return $propertyPhpDoc->resolvePropertyType($propertyName);
    }


    public function resolvePropertyFromParentDocComments(string $propertyName): ?Type
    {
        $parentClass = $this->getParent();

        if (! $parentClass) {
            return null;
        }

        if ($parentClass->hasLoadedProperties()) {
            return $parentClass->getProperty($propertyName)?->unwrapType($this->scope->config);
        }

        return $parentClass->resolvePropertyFromDocComments($propertyName);
    }


    public function getPhpDoc(): ?PhpDoc
    {
        if (isset($this->docComment)) {
            return $this->docComment;
        }

        $comment = $this->getReflection()->getDocComment();

        if ($comment) {
            $this->docComment = new PhpDoc($comment, $this->scope);
        }

        return $this->docComment;
    }


    public function getPropertyDocComment(ReflectionProperty $propertyReflection): ?PhpDoc
    {
        $propertyDocComment = $propertyReflection->getDocComment();

        if (! $propertyDocComment) {
            return null;
        }

        return new PhpDoc($propertyDocComment, $this->scope);
    }


    /**
     * @param PhpFunctionArgument[] $args
     *
     * @return PhpClassMethod<TClass>
     */
    public function getMethod(string $name, array $args = []): PhpClassMethod
    {
        return new PhpClassMethod(
            phpClass: $this,
            methodName: $name,
            scope: $this->scope->createChildScope(className: $this->className, methodName: $name),
            args: $args,
        );
    }

    /**
     * @return class-string[]
     */
    public function getTraits(): array
    {
        $traitUsesRecursive = function ($trait) use (&$traitUsesRecursive): array {
            $traits = class_uses($trait) ?: [];

            foreach ($traits as $trait) {
                $traits += $traitUsesRecursive($trait);
            }

            return $traits;
        };

        return $traitUsesRecursive($this->className);
    }


    public function traverse(NodeVisitorAbstract $nodeVisitor): bool
    {
        if (! isset($this->ast)) {
            $classFileName = $this->getReflection()->getFileName();

            if (! $classFileName) {
                return false;
            }

            $code = file_get_contents($classFileName);

            if (! $code) {
                throw new Exception('Error reading file "' . $classFileName . '".');
            }

            $parser = (new ParserFactory)->createForNewestSupportedVersion();

            $ast = $parser->parse($code);

            if (! $ast) {
                throw new Exception('Error parsing file "' . $classFileName . '".');
            }

            $this->ast = $ast;
        }

        $traverser = new NodeTraverser;

        $traverser->addVisitor($nodeVisitor);

        $traverser->traverse($this->ast);

        return true;
    }


    public function getNameResolver(): ?NameResolver
    {
        if (! isset($this->nameResolver)) {
            $nameResolver = new NameResolver;

            $traversed = $this->traverse($nameResolver);

            if ($traversed) {
                // Add name aliases from traits since some PHPDoc comments might be
                // defined in traits but resolved in class context.
                foreach ($this->getTraits() as $traitName) {
                    $this->scope->getPhpClassInDeeperScope($traitName)->traverse($nameResolver);
                }

                $this->nameResolver = $nameResolver;
            }
        }

        return $this->nameResolver;
    }


    public function exists(): bool
    {
        $className = PhpClass::addLeadingBackslash($this->className);

        if (class_exists($className) || interface_exists($className)) {
            return true;
        }

        return false;
    }


    /**
     * @return ?PhpClass<object>
     */
    public function getParent(): ?PhpClass
    {
        $parentReflection = $this->getReflection()->getParentClass();

        if (! $parentReflection) {
            return null;
        }

        $parentClass = $this->scope->getPhpClassInDeeperScope($parentReflection->getName());

        $extendsTag = $this->getPhpDoc()?->getExtendsTag();

        if ($extendsTag?->genericTypes) {
            $parentClass->setTemplateTypeValues($extendsTag->genericTypes);
        }

        return $parentClass;
    }


    /**
     * @param array<?UnresolvedType> $templateTypeValues
     */
    public function setTemplateTypeValues(array $templateTypeValues): void
    {
        $classTemplateTypes = $this->getPhpDoc()?->getTemplateTypes() ?? [];

        $expectedTypesCount = count($classTemplateTypes);
        $receivedTypesCount = count($templateTypeValues);

        if ($expectedTypesCount === 0 || $receivedTypesCount === 0) {
            return;
        }

        if ($receivedTypesCount === $expectedTypesCount) {
            $this->scope->constructorTemplateTypes = array_combine(
                keys: array_keys($classTemplateTypes),
                values: $templateTypeValues,
            );

        } else if ($this->scope->isDebugModeEnabled()) {
            throw new Exception(sprintf('Class "%s" expected %d template types but received %d', ...[
                $this->className,
                $expectedTypesCount,
                $receivedTypesCount,
            ]));
        }
    }



    public static function basename(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }

    /**
     * @return class-string
     */
    public static function addLeadingBackslash(string $name): string
    {
        /** @var class-string */
        $className = '\\' . PhpClass::removeLeadingBackslash($name);

        return $className;
    }

    /**
     * @return class-string
     */
    public static function removeLeadingBackslash(string $name): string
    {
        /** @var class-string */
        $className = ltrim($name, '\\');

        return $className;
    }

    /**
     * @var array<class-string, PhpClass<object>>
     */
    public static array $cache = [];
}
