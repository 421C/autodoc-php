<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Analyzer\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;

/**
 * @extends CallContext<StaticCall>
 */
class StaticCallContext extends CallContext
{
    public readonly string $methodName;
    public readonly ArgumentList $argTypes;

    /** @var ?class-string */
    public readonly ?string $className;

    public function __construct(StaticCall $node, Scope $scope)
    {
        parent::__construct($node, $scope);

        $this->methodName = (string) $scope->getRawValueFromNode($node->name);
        $this->className = $node->class instanceof Node\Name ? $scope->getResolvedClassName($node->class) : null;
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }
}
