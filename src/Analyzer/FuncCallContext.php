<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;


class FuncCallContext
{
    public readonly ?string $functionName;
    public readonly ArgumentList $argTypes;

    public function __construct(
        public readonly FuncCall $node,
        public readonly Scope $scope,
    ) {
        $rawName = $node->name instanceof Node\Name
            ? $node->name->name
            : $scope->getRawValueFromNode($node->name);

        $this->functionName = is_string($rawName) ? $rawName : null;
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }
}
