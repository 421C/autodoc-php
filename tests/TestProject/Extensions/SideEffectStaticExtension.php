<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\StaticCallContext;
use AutoDoc\Extensions\StaticCallExtension;

/**
 * Records the first argument of `...::captureBodyStatic(...)` as the request
 * body, proving the side-effect hook fires for a bare static-call statement.
 */
class SideEffectStaticExtension extends StaticCallExtension
{
    /** @var list<string> */
    public static array $dispatchLog = [];

    public function handleSideEffect(StaticCallContext $context): void
    {
        self::$dispatchLog[] = $context->methodName;

        if ($context->methodName === 'captureBodyStatic') {
            $context->setRequestType($context->argTypes->get(0)->unwrapType($context->scope->config));
        }
    }
}
