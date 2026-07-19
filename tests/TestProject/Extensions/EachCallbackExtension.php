<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\ArgumentList;
use AutoDoc\Extensions\MethodCallContext;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\MethodCallExtension;

class EachCallbackExtension extends MethodCallExtension
{
    public function getReturnType(MethodCallContext $context): ?Type
    {
        if ($context->methodName !== 'eachItem') {
            return null;
        }

        $callable = $context->argTypes->get(0)->unwrapType($context->scope->config);

        if (! $callable instanceof CallableType) {
            return null;
        }

        return $callable->resolveParameterTypeAfterInvocation(
            0,
            ArgumentList::fromTypes([$context->getVarType()], $context->scope),
            $context->node,
        );
    }
}
