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

    /**
     * @var array<int|string, Node>
     */
    public array $assignedProperties = [];


    public function resolve(): Type
    {
        $type = $this->scope->resolveType($this->node, isFinalResponse: $this->isFinalResponse);

        foreach ($this->assignedProperties as $key => $propNode) {
            $valueType = new UnresolvedParserNodeType($propNode, $this->scope);

            if ($type instanceof ArrayType) {
                if ($type->shape && $key) {
                    $type->shape[$key] = $valueType;

                } else {
                    if ($type->itemType) {
                        $type->itemType = new UnionType([$type->itemType->unwrapType($this->scope->config), $valueType]);

                    } else {
                        $type->itemType = $valueType;
                    }
                }

            } else if ($type instanceof ObjectType) {
                if ($key) {
                    $type->properties[(string) $key] = $valueType;
                }
            }
        }

        if ($this->description) {
            if ($type->description) {
                $type->description = $type->description . "\n\n" . $this->description;

            } else {
                $type->description = $this->description;
            }
        }

        $type->examples = $type->examples ?: $this->examples;

        return $type;
    }
}
