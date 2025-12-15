<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\Scope;
use PhpParser\Node;


class UnresolvedParserNodeType extends UnresolvedType
{
    public function __construct(
        public Node $node,
        public Scope $scope,
        public ?string $description = null,
        public bool $isFinalResponse = false,
    ) {}

    public function resolve(): Type
    {
        $type = $this->scope->resolveType($this->node, isFinalResponse: $this->isFinalResponse);

        $type->addDescription($this->description);
        $type->examples = $type->examples ?: $this->examples;

        return $type;
    }
}
