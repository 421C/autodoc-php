<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Traits;

use AutoDoc\Analyzer\Flow\ScopeEventLog;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\UnresolvedVariableType;
use PhpParser\Comment;
use PhpParser\Node;

/**
 * @phpstan-require-extends Scope
 */
trait StoresVariables
{
    public ScopeEventLog $eventLog;

    /**
     * @param Comment[] $comments
     */
    public function assignVariable(
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

            $type = new UnresolvedParserNodeType(node: $valueNode, scope: $this);

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

        $this->eventLog->assign(
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
    public function mutateVariable(
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

        $this->eventLog->mutate($varNode->name, $attributes, $startFilePos, $endFilePos, $path, $dynamicAttribute);
    }


    public function getVariableType(Node\Expr\Variable $varNode): ?Type
    {
        if (! is_string($varNode->name)) {
            return null;
        }

        if ($varNode->name === 'this') {
            if ($this->className) {
                return new ObjectType(className: $this->className);
            }
        }

        if (! $this->eventLog->hasVariable($varNode->name)) {
            return null;
        }

        /** @var int */
        $nodeStartFilePos = $varNode->getAttribute('startFilePos');

        return new UnresolvedVariableType(
            varName: $varNode->name,
            scope: $this,
            varStartFilePos: $nodeStartFilePos,
            readBranchPath: $this->eventLog->getBranchPathAtPosition($nodeStartFilePos),
        );
    }


    /**
     * @param string[]|null $variableNames
     */
    public function transferVariablesFrom(Scope $parentScope, ?array $variableNames = null): void
    {
        if (! $this->callerNode) {
            return;
        }

        /** @var int */
        $callerNodeStartFilePos = $this->callerNode->getAttribute('startFilePos');

        $transferNames = $variableNames ?? $parentScope->eventLog->getAssignedVariableNames();

        $readBranchPath = $parentScope->eventLog->getBranchPathAtPosition($callerNodeStartFilePos);

        foreach ($transferNames as $varName) {
            if (! $parentScope->eventLog->hasVariable($varName)) {
                continue;
            }

            $parentType = new UnresolvedVariableType(
                varName: $varName,
                scope: $parentScope,
                varStartFilePos: $callerNodeStartFilePos,
                readBranchPath: $readBranchPath,
            );

            $this->eventLog->assign($varName, $parentType, 0);
        }
    }
}
