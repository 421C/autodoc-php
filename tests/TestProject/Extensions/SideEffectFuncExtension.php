<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\Extensions\FuncCallExtension;

/**
 * Records the first argument of `capture_body_func(...)` as the request body,
 * proving the side-effect hook fires for a bare function-call statement.
 */
class SideEffectFuncExtension extends FuncCallExtension
{
    /** @var list<?string> */
    public static array $dispatchLog = [];

    public function handleSideEffect(FuncCallContext $context): void
    {
        self::$dispatchLog[] = $context->functionName;

        if ($context->functionName === 'capture_body_func') {
            $context->setRequestType($context->argTypes->get(0)->unwrapType($context->scope->config));
        }
    }
}
