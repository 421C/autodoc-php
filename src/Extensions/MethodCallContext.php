<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;

/**
 * @extends CallContext<MethodCall|NullsafeMethodCall>
 */
class MethodCallContext extends CallContext
{
    public readonly string $methodName;
    public readonly ArgumentList $argTypes;

    private ?Type $resolvedVarType = null;

    public function __construct(MethodCall|NullsafeMethodCall $node, Scope $scope)
    {
        parent::__construct($node, $scope);

        $this->methodName = (string) $scope->getRawValueFromNode($node->name);
        $this->argTypes = ArgumentList::fromArgNodes($node->args, $scope);
    }

    public function getVarType(): Type
    {
        return $this->resolvedVarType ??= $this->scope->resolveType($this->node->var);
    }
}
