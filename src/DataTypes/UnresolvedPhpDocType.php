<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\DocBlock\PhpDoc;
use AutoDoc\Analyzer\DocBlock\PhpDocBinding;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;


class UnresolvedPhpDocType extends UnresolvedType
{
    public function __construct(
        public TypeNode $typeNode,
        public PhpDoc $phpDoc,
        public ?string $description = null,
        public ?PhpDocBinding $binding = null,
    ) {}

    public ?Type $fallbackType = null;


    public function resolve(): Type
    {
        $resolvedType = $this->phpDoc->resolveTypeFromNode($this->typeNode, $this->binding);

        if (! $resolvedType) {
            if ($this->phpDoc->scope->isDebugModeEnabled()) {
                $resolvedType = new UnknownType((string) $this->typeNode);

            } else {
                $resolvedType = new UnknownType;
            }
        }

        $resolvedType->addDescription($this->description);
        $resolvedType->examples = $this->examples ?: $resolvedType->examples;
        $resolvedType->required = $this->required ?: $resolvedType->required;

        return $resolvedType;
    }


    public function getIdentifier(): ?string
    {
        if ($this->typeNode instanceof IdentifierTypeNode) {
            return $this->typeNode->name;
        }

        return null;
    }
}
