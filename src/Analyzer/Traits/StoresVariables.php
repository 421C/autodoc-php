<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Traits;

use AutoDoc\Analyzer\PhpCondition;
use AutoDoc\Analyzer\PhpVariable;
use AutoDoc\Analyzer\PhpVariableMutation;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use AutoDoc\DataTypes\UnresolvedVariableType;
use PhpParser\Comment;
use PhpParser\Node;

/**
 * @phpstan-require-extends Scope
 * @phpstan-import-type VariableMutationChanges from PhpVariableMutation
 */
trait StoresVariables
{
    /**
     * @param Comment[] $comments
     * @param PhpCondition[] $conditions
     */
    public function assignVariable(
        Node\Expr\Variable $varNode,
        Node|Type $valueNode,
        array $comments = [],
        array $conditions = [],
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

        if (! isset($this->variables[$varNode->name])) {
            $this->variables[$varNode->name] = new PhpVariable($varNode->name);
        }

        /** @var int */
        $startFilePos = $varNode->getAttribute('startFilePos');

        $this->variables[$varNode->name]->mutations[$varNode->getStartLine()][$startFilePos] = new PhpVariableMutation(
            changes: ['type' => $type],
            startFilePos: $startFilePos,
            endFilePos: $endFilePos,
            conditions: $conditions,
        );
    }


    /**
     * @param VariableMutationChanges $changes
     * @param PhpCondition[] $conditions
     */
    public function mutateVariable(
        Node\Expr\Variable $varNode,
        array $changes,
        int $depth = 0,
        array $conditions = [],
    ): void {

        if (! is_string($varNode->name)) {
            return;
        }

        if (! isset($this->variables[$varNode->name])) {
            $this->variables[$varNode->name] = new PhpVariable($varNode->name);
        }

        /** @var int */
        $startFilePos = $varNode->getAttribute('startFilePos');

        /** @var int */
        $endFilePos = $varNode->getAttribute('endFilePos');

        $this->variables[$varNode->name]->mutations[$varNode->getStartLine()][$startFilePos] = new PhpVariableMutation(
            changes: $changes,
            startFilePos: $startFilePos,
            endFilePos: $endFilePos,
            conditions: $conditions,
        );
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

        if (empty($this->variables[$varNode->name])) {
            return null;
        }

        /** @var int */
        $nodeStartFilePos = $varNode->getAttribute('startFilePos');
        $currentLine = $varNode->getStartLine();

        return new UnresolvedVariableType(
            phpVariable: $this->variables[$varNode->name],
            scope: $this,
            varLine: $currentLine,
            varStartFilePos: $nodeStartFilePos,
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

        $phpVariables = [];

        if ($variableNames === null) {
            $phpVariables = $parentScope->variables;

        } else {
            foreach ($variableNames as $varName) {
                if (isset($parentScope->variables[$varName])) {
                    $phpVariables[] = $parentScope->variables[$varName];
                }
            }
        }

        foreach ($phpVariables as $phpVariable) {
            /** @var int */
            $callerNodeStartFilePos = $this->callerNode->getAttribute('startFilePos');
            $callerNodeLine = $this->callerNode->getStartLine();

            $this->variables[$phpVariable->name] = new PhpVariable(
                name: $phpVariable->name,
                mutations: [
                    [new PhpVariableMutation(
                        changes: [
                            'type' => new UnresolvedVariableType(
                                phpVariable: $phpVariable,
                                scope: $parentScope,
                                varLine: $callerNodeLine,
                                varStartFilePos: $callerNodeStartFilePos,
                            ),
                        ],
                        startFilePos: 0,
                        endFilePos: 0,
                        conditions: [],
                    )],
                ]
            );
        }
    }
}
