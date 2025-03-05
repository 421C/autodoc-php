<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedClassType;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\UnresolvedPhpDocType;
use AutoDoc\DataTypes\UnresolvedReflectionType;
use AutoDoc\DataTypes\UnresolvedType;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use ReflectionFunction;
use ReflectionFunctionAbstract;


class PhpFunction
{
    public function __construct(
        string|ReflectionFunctionAbstract $nameOrReflection,
        private Scope $scope,

        /**
         * @var PhpFunctionArgument[]
         */
        private array $args = [],
    ) {
        if (is_string($nameOrReflection)) {
            $this->reflection = new ReflectionFunction($nameOrReflection);

        } else {
            $this->reflection = $nameOrReflection;
        }
    }

    private ReflectionFunctionAbstract $reflection;

    private ?PhpDoc $docComment = null;


    public function getReturnType(): Type
    {
        if ($this->reflection->getName() == 'base64_encode') {
            return new StringType(format: 'byte');
        }

        return $this->getTypeFromPhpDocReturnTag()
            ?? $this->getTypeFromNativeReturnType()
            ?? new UnknownType;
    }


    public function getReflection(): ReflectionFunctionAbstract
    {
        return $this->reflection;
    }


    public function getTypeFromPhpDocTag(PhpDocTagValueNode $tagValueNode): ?UnresolvedPhpDocType
    {
        $phpDoc = $this->getPhpDoc();

        if (! $phpDoc) {
            return null;
        }

        $phpDoc->templateTypes = array_merge(
            $phpDoc->getTemplateTypes(),
            $this->scope->getCurrentPhpClass()?->getPhpDoc()?->getTemplateTypes() ?? [],
            $this->fillTemplateTypesFromParameters(),
        );

        if ($tagValueNode instanceof ReturnTagValueNode) {
            return $phpDoc->createUnresolvedType(
                typeNode: $tagValueNode->type,
                description: $tagValueNode->description,
            );

        } else if ($tagValueNode instanceof GenericTagValueNode) {
            $tokens = new TokenIterator((new Lexer)->tokenize($tagValueNode->value));
            $typeNode = (new TypeParser)->parse($tokens);

            return $phpDoc->createUnresolvedType($typeNode);
        }

        return null;
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
        $reflectionReturnType = $this->reflection->getReturnType();

        if (! $reflectionReturnType) {
            return null;
        }

        return new UnresolvedReflectionType($reflectionReturnType, $this->scope);
    }


    private function getParsedArgumentType(string $name): ?UnresolvedParserNodeType
    {
        foreach ($this->reflection->getParameters() as $paramIndex => $reflectionParameter) {
            if ($name !== $reflectionParameter->name) {
                continue;
            }

            foreach ($this->args as $argIndex => $arg) {
                if ($arg->node instanceof Node\Arg) {
                    if ($arg->node->name === null) {
                        if ($paramIndex === $argIndex) {
                            return new UnresolvedParserNodeType(node: $arg->node->value, scope: $arg->scope);
                        }

                    } else if ($arg->node->name->name === $name) {
                        return new UnresolvedParserNodeType(node: $arg->node->value, scope: $arg->scope);
                    }
                }
            }
        }

        return null;
    }


    public function getPhpDoc(): ?PhpDoc
    {
        if (isset($this->docComment)) {
            return $this->docComment;
        }

        $comment = $this->reflection->getDocComment();

        if ($comment) {
            $this->docComment = new PhpDoc($comment, $this->scope);
        }

        return $this->docComment;
    }


    /**
     * @return array<string, ?UnresolvedType>
     */
    public function fillTemplateTypesFromParameters(): array
    {
        $phpDocParamTypes = $this->getPhpDoc()?->getParameters() ?? [];

        if ($this->reflection->getName() === '__construct') {
            // Need to check not only @param tags but also @var tags for promoted constructor properties.

            $propNodeVisitor = new ClassConstructorPropertyVisitor($this->scope);

            $this->scope->getCurrentPhpClass()?->traverse($propNodeVisitor);

            $phpDocParamTypes = array_merge($phpDocParamTypes, $propNodeVisitor->promotedProperties);
        }

        $templateTypes = [];

        foreach ($phpDocParamTypes as $name => $unresolvedType) {
            $resolvedType = $unresolvedType->resolve();

            if ($resolvedType instanceof UnknownType) {
                $templateTypeName = $unresolvedType->getIdentifier();

                if ($templateTypeName) {
                    $templateTypes[$templateTypeName] = $this->getParsedArgumentType($name);
                }
            }

            if ($resolvedType instanceof ClassStringType) {
                if (isset($resolvedType->classTemplateType)) {
                    $templateTypeName = $resolvedType->classTemplateType->getIdentifier();

                    if ($templateTypeName) {
                        $parameterValue = $this->getParsedArgumentType($name)?->resolve();

                        if ($parameterValue instanceof ClassStringType) {
                            $templateTypes[$templateTypeName] = new UnresolvedClassType($parameterValue->className, $this->scope);
                        }
                    }
                }
            }
        }

        return $templateTypes;
    }
}
