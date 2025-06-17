<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnknownType;
use Exception;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;

class PhpDocRequestParamTag
{
    public function __construct(
        private PhpDocTagNode $tag,
        private PhpDoc $phpDoc,
    ) {}

    /**
     * @return array{string, Type}
     */
    public function resolve(): ?array
    {
        if (! $this->tag->value instanceof GenericTagValueNode) {
            if ($this->phpDoc->scope->isDebugModeEnabled()) {
                throw new Exception("Failed to parse {$this->tag->name} tag");
            }

            return null;
        }

        $parts = preg_split('/\s+/', $this->tag->value->value, 2) ?: [];

        if (count($parts) > 2) {
            if ($this->phpDoc->scope->isDebugModeEnabled()) {
                throw new Exception("{$this->tag->name} tag must have 1-2 arguments, got " . count($parts));
            }

            return null;
        }

        $paramName = $parts[0];
        $typeString = $parts[1] ?? 'string';

        if ($paramName === '') {
            if ($this->phpDoc->scope->isDebugModeEnabled()) {
                throw new Exception("{$this->tag->name} tag must have a parameter name");
            }

            return null;
        }

        $parameterDefinitionObjectPassed = $typeString[0] === '{';

        if ($parameterDefinitionObjectPassed) {
            $paramDefinitionType = $this->phpDoc->createUnresolvedType($this->phpDoc->createTypeNode('array' . $typeString))->resolve();

            if (! $paramDefinitionType instanceof ArrayType) {
                if ($this->phpDoc->scope->isDebugModeEnabled()) {
                    throw new Exception("Failed to parse {$this->tag->name} tag");
                }

                return null;
            }

            $type = $paramDefinitionType->shape['type'] ?? new UnknownType;

            if (isset($paramDefinitionType->shape['description'])) {
                if ($paramDefinitionType->shape['description'] instanceof StringType && is_string($paramDefinitionType->shape['description']->value)) {
                    $type->description = $paramDefinitionType->shape['description']->value;

                } else if ($this->phpDoc->scope->isDebugModeEnabled()) {
                    throw new Exception("Failed to parse {$this->tag->name} tag: 'description' must be a string");
                }
            }

            if (isset($paramDefinitionType->shape['example'])) {
                if ($paramDefinitionType->shape['example'] instanceof StringType && is_string($paramDefinitionType->shape['example']->value)) {
                    $type->example = $paramDefinitionType->shape['example']->value;

                } else if ($this->phpDoc->scope->isDebugModeEnabled()) {
                    throw new Exception("Failed to parse {$this->tag->name} tag: 'example' must be a string");
                }
            }

            if (isset($paramDefinitionType->shape['required'])) {
                if ($paramDefinitionType->shape['required'] instanceof BoolType && isset($paramDefinitionType->shape['required']->value)) {
                    $type->required = $paramDefinitionType->shape['required']->value;

                } else if ($this->phpDoc->scope->isDebugModeEnabled()) {
                    throw new Exception("Failed to parse {$this->tag->name} tag: 'required' must be a boolean");
                }
            } else {
                $type->required = false;
            }

            if (isset($paramDefinitionType->shape['deprecated'])) {
                if ($paramDefinitionType->shape['deprecated'] instanceof BoolType && isset($paramDefinitionType->shape['deprecated']->value)) {
                    $type->deprecated = $paramDefinitionType->shape['deprecated']->value;

                } else if ($this->phpDoc->scope->isDebugModeEnabled()) {
                    throw new Exception("Failed to parse {$this->tag->name} tag: 'deprecated' must be a boolean");
                }
            }

            return [$paramName, $type];
        }

        return [$paramName, $this->phpDoc->createUnresolvedType($this->phpDoc->createTypeNode($typeString))];
    }
}
