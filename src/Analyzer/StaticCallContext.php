<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;


class StaticCallContext
{
    public readonly string $methodName;
    public readonly ArgumentList $argTypes;

    /** @var ?class-string */
    public readonly ?string $className;

    public function __construct(
        public readonly StaticCall $node,
        public readonly Scope $scope,
    ) {
        $this->methodName = (string) $scope->getRawValueFromNode($node->name);
        $this->className = $node->class instanceof Node\Name ? $scope->getResolvedClassName($node->class) : null;
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }
}
