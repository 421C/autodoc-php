<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr;


class ThrowContext
{
    private ?Type $resolvedThrownType = null;

    public function __construct(
        public readonly Expr $node,
        public readonly Scope $scope,
    ) {}

    public function getThrownType(): Type
    {
        return $this->resolvedThrownType ??= $this->scope->resolveType($this->node);
    }

    /**
     * @return ?class-string
     */
    public function getThrownClassName(): ?string
    {
        $thrownType = $this->getThrownType();

        return $thrownType instanceof ObjectType ? $thrownType->className : null;
    }
}
