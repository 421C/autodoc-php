<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Analyzer\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

/**
 * @extends CallContext<FuncCall>
 */
class FuncCallContext extends CallContext
{
    public readonly ?string $functionName;
    public readonly ArgumentList $argTypes;

    public function __construct(FuncCall $node, Scope $scope)
    {
        parent::__construct($node, $scope);

        $rawName = $node->name instanceof Node\Name
            ? $scope->getResolvedFunctionName($node->name)
            : $scope->getRawValueFromNode($node->name);

        $this->functionName = is_string($rawName) ? $rawName : null;
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }
}
