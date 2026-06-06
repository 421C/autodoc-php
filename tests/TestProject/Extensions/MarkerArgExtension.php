<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\MethodCallContext;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\MethodCallExtension;

/**
 * Echoes a call's first argument type into the request body (`markRequest`) or
 * the return type (`markReturn`), so a test can compare how a local variable
 * resolves on each path.
 */
class MarkerArgExtension extends MethodCallExtension
{
    public function getRequestType(MethodCallContext $context): ?Type
    {
        return $context->methodName === 'markRequest'
            ? $context->argTypes->get(0)->unwrapType($context->scope->config)
            : null;
    }

    public function getReturnType(MethodCallContext $context): ?Type
    {
        return $context->methodName === 'markReturn'
            ? $context->argTypes->get(0)->unwrapType($context->scope->config)
            : null;
    }
}
