<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ClassStringType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\DataTypes\UnresolvedClassType;
use AutoDoc\DataTypes\UnresolvedPhpDocType;
use AutoDoc\DataTypes\UnresolvedReflectionType;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use ReflectionException;
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


    public function getReturnType(?Type $analyzedType = null, ?Type $phpDocType = null): Type
    {
        if ($this->reflection->getName() == 'base64_encode') {
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
        $reflectionReturnType = $this->reflection->getReturnType();

        if (! $reflectionReturnType) {
            return null;
        }

        return new UnresolvedReflectionType($reflectionReturnType, $this->scope);
    }


    public function getDefaultArgumentValue(string $name): mixed
    {
        try {
            foreach ($this->reflection->getParameters() as $paramIndex => $reflectionParameter) {
                if ($name !== $reflectionParameter->name) {
                    continue;
                }

                return $reflectionParameter->getDefaultValue();
            }

        } catch (ReflectionException) {
        }

        return null;
    }


    public function getParsedArgumentType(string $name): ?Type
    {
        foreach ($this->reflection->getParameters() as $paramIndex => $reflectionParameter) {
            if ($name !== $reflectionParameter->name) {
                continue;
            }

            foreach ($this->args as $argIndex => $arg) {
                if ($arg->node instanceof Node\Arg) {
                    if ($arg->node->name === null) {
                        if ($paramIndex === $argIndex) {
                            return $arg->getType();
                        }

                    } else if ($arg->node->name->name === $name) {
                        return $arg->getType();
                    }

                } else if ($arg->node instanceof Type) {
                    if ($paramIndex === $argIndex) {
                        return $arg->getType();
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
     * @return array<string, ?Type>
     */
    public function fillTemplateTypesFromParameters(): array
    {
        $templateTypes = $this->getPhpDoc()?->getTemplateTypes() ?? [];
        $phpDocParamTypes = $this->getPhpDoc()?->getParameters() ?? [];

        if ($this->reflection->getName() === '__construct') {
            $templateTypes = array_merge($templateTypes, $this->scope->getCurrentPhpClass()?->getPhpDoc()?->getTemplateTypes() ?? []);

            $propNodeVisitor = new ClassConstructorPropertyVisitor($this->scope);

            $this->scope->getCurrentPhpClass()?->traverse($propNodeVisitor);

            $phpDocParamTypes = array_merge($phpDocParamTypes, $propNodeVisitor->promotedProperties);
        }

        foreach ($phpDocParamTypes as $name => $unresolvedType) {
            $docTypeNode = $unresolvedType->typeNode;

            if ($docTypeNode instanceof IdentifierTypeNode) {
                if (array_key_exists($docTypeNode->name, $templateTypes)) {
                    $parsedArgumentType = $this->getParsedArgumentType($name)?->unwrapType($this->scope->config);

                    if ($parsedArgumentType) {
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
                        $argumentType = $this->getParsedArgumentType($name)?->unwrapType($this->scope->config);

                        if ($argumentType instanceof ArrayType) {
                            $argumentType->convertShapeToTypePair($this->scope->config);

                            $templateTypes[$itemType->name] = $argumentType->itemType;
                        }
                    }

                } else if ($docTypeNode->type->name === 'class-string') {
                    $paramName = $docTypeNode->genericTypes[0]->name ?? null;

                    if ($paramName && array_key_exists($paramName, $templateTypes)) {
                        $parameterValue = $this->getParsedArgumentType($name)?->unwrapType($this->scope->config);

                        if ($parameterValue instanceof ClassStringType) {
                            $templateTypes[$paramName] = new UnresolvedClassType($parameterValue->className, $this->scope);
                        }
                    }
                }
            }
        }

        return $templateTypes;
    }
}
