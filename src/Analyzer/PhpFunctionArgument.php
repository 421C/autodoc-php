<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\UnresolvedParserNodeType;
use PhpParser\Node;

class PhpFunctionArgument
{
    public function __construct(
        public Node\Arg|Node\VariadicPlaceholder $node,
        public Scope $scope,
    ) {}

    public function getType(): ?UnresolvedParserNodeType
    {
        if ($this->node instanceof Node\Arg) {
            return new UnresolvedParserNodeType(node: $this->node->value, scope: $this->scope);
        }

        return null;
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $argNodes
     *
     * @return PhpFunctionArgument[]
     */
    public static function list(array $argNodes, Scope $scope): array
    {
        return array_map(fn ($argNode) => new PhpFunctionArgument($argNode, $scope), $argNodes);
    }
}
