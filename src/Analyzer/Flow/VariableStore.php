<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\UnresolvedVariableType;
use PhpParser\Comment;
use PhpParser\Node;

final class VariableStore
{
    public readonly ScopeEventLog $events;

    /** @var array<string, Type> */
    public array $resolvedTypes = [];

    public function __construct(
        private readonly Scope $scope,
    ) {
        $this->events = new ScopeEventLog;
    }

    /**
     * @param Comment[] $comments
     */
    public function assign(
        Node\Expr\Variable $varNode,
        Node|Type $valueNode,
        array $comments = [],
        bool $isTypeAnnotation = false,
    ): void {

        if ($valueNode instanceof Node) {
            $valueNode->setAttribute('comments', array_merge(
                $comments,
                $varNode->getComments(),
                $valueNode->getComments(),
            ));

            $type = new UnresolvedParserNodeType(node: $valueNode, scope: $this->scope);

            /** @var int */
            $endFilePos = $valueNode->getAttribute('endFilePos');

        } else {
            $type = $valueNode;

            /** @var int */
            $endFilePos = $varNode->getAttribute('endFilePos');
        }

        if (! is_string($varNode->name)) {
            return;
        }

        /** @var int */
        $startFilePos = $varNode->getAttribute('startFilePos');

        $this->events->assign(
            varName: $varNode->name,
            type: $type,
            startFilePos: $startFilePos,
            endFilePos: $endFilePos,
            isTypeAnnotation: $isTypeAnnotation,
        );
    }


    /**
     * @param array<int|string, Type> $attributes
     * @param list<int|string|null> $path
     */
    public function mutate(
        Node\Expr\Variable $varNode,
        array $attributes,
        array $path = [],
        ?Type $dynamicAttribute = null,
    ): void {

        if (! is_string($varNode->name)) {
            return;
        }

        /** @var int */
        $startFilePos = $varNode->getAttribute('startFilePos');

        /** @var int */
        $endFilePos = $varNode->getAttribute('endFilePos');

        $this->events->mutate($varNode->name, $attributes, $startFilePos, $endFilePos, $path, $dynamicAttribute);
    }


    public function getType(Node\Expr\Variable $varNode): ?Type
    {
        if (! is_string($varNode->name)) {
            return null;
        }

        if ($varNode->name === 'this') {
            if ($this->scope->className) {
                return new ObjectType(className: $this->scope->className);
            }
        }

        if (! $this->events->hasVariable($varNode->name)) {
            return null;
        }

        /** @var int */
        $nodeStartFilePos = $varNode->getAttribute('startFilePos');

        return new UnresolvedVariableType(
            varName: $varNode->name,
            scope: $this->scope,
            varStartFilePos: $nodeStartFilePos,
            readBranchPath: $this->events->getBranchPathAtPosition($nodeStartFilePos),
        );
    }


    /**
     * @param string[]|null $variableNames
     */
    public function transferFrom(Scope $parentScope, ?array $variableNames = null): void
    {
        if (! $this->scope->callerNode) {
            return;
        }

        /** @var int */
        $callerNodeStartFilePos = $this->scope->callerNode->getAttribute('startFilePos');

        $transferNames = $variableNames ?? $parentScope->variables->events->getAssignedVariableNames();

        $readBranchPath = $parentScope->variables->events->getBranchPathAtPosition($callerNodeStartFilePos);

        foreach ($transferNames as $varName) {
            if (! $parentScope->variables->events->hasVariable($varName)) {
                continue;
            }

            $parentType = new UnresolvedVariableType(
                varName: $varName,
                scope: $parentScope,
                varStartFilePos: $callerNodeStartFilePos,
                readBranchPath: $readBranchPath,
            );

            $this->events->assign($varName, $parentType, 0);
        }
    }
}
