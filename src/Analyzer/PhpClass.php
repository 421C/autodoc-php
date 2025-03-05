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
use Exception;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Stringable;

/**
 * @template TClass of object
 */
class PhpClass
{
    public function __construct(
        /**
         * @var class-string<TClass>
         */
        public string $className,
        public Scope $scope,
    ) {}

    public ?NameResolver $nameResolver = null;

    public ?Type $typeToDisplay = null;

    public bool $isFinalResponse = false;

    /**
     * @var array<string, Type>
     */
    private array $properties;

    /**
     * @var ?ReflectionClass<TClass>
     */
    private ?ReflectionClass $classReflection;

    private ?PhpDoc $docComment = null;

    /**
     * @var Node\Stmt[]
     */
    private array $ast;


    public function resolveType(bool $useExtensions = true): Type
    {
        if ($useExtensions) {
            $returnType = $this->scope->getReturnTypeFromExtensions($this);

            if ($returnType !== null) {
                return $returnType->unwrapType();
            }
        }

        $objectType = new ObjectType(className: $this->className);

        if ($this->typeToDisplay) {
            $objectType->typeToDisplay = $this->typeToDisplay->unwrapType();

        } else if ($this->getReflection()->isEnum()) {
            $objectType->typeToDisplay = (new PhpEnum($this))->resolveType();

        } else if ($this->getReflection()->isSubclassOf(DateTimeInterface::class)) {
            $objectType->typeToDisplay = new StringType(format: 'date-time');

        } else if ($this->getReflection()->isSubclassOf(Stringable::class)) {
            $objectType->typeToDisplay = new StringType;
        }

        if ($this->scope->depth > $this->scope->config->data['max_depth']) {
            return $objectType;
        }

        $classPhpDoc = $this->getPhpDoc();

        if ($classPhpDoc) {
            $objectType->description = $classPhpDoc->getText();
        }

        $objectType->properties = $this->getPropertiesFromReflection();

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
    private function getPropertiesFromReflection(): array
    {
        if ($this->scopeAllowsUsingCache() && isset($this->properties)) {
            return $this->properties;
        }

        $properties = [];

        foreach ($this->getReflection()->getProperties() as $propertyReflection) {
            if (! $propertyReflection->isPublic()) {
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

            if ($propertyPhpDoc) {
                $propertyType->description = $propertyPhpDoc->getText();
                $propertyType->examples = $propertyPhpDoc->getExampleValues() ?: null;
            }

            $properties[$propertyName] = $propertyType;
        }

        $properties = $this->handlePhpDocPropertyTags($properties);

        if ($this->scopeAllowsUsingCache()) {
            $this->properties = $properties;
        }

        return $properties;
    }


    public function getProperty(string $name): ?Type
    {
        return $this->getPropertiesFromReflection()[$name] ?? null;
    }


    public function hasLoadedProperties(): bool
    {
        return isset($this->properties);
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
            return new BoolType;
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
            return $parentClass->getProperty($propertyName)?->unwrapType();
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
