<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;
use PhpParser\Node;


/**
 * A parameter's value inside an analyzed body: the passed argument when it
 * resolves, otherwise the declared native type.
 */
class UnresolvedParameterType extends UnresolvedType
{
    public function __construct(
        public Type $argumentType,
        public Node $nativeTypeNode,
        public Scope $scope,
    ) {}

    public function resolve(): Type
    {
        $type = $this->argumentType->unwrapType($this->scope->config);

        if ($type instanceof UnknownType) {
            return $this->scope->resolveType($this->nativeTypeNode);
        }

        return $type;
    }
}
