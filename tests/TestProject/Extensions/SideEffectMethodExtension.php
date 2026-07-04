<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\MethodCallContext;
use AutoDoc\DataTypes\StringType;
use AutoDoc\Extensions\MethodCallExtension;
use PhpParser\Node\Expr\Variable;

/**
 * Exercises the side-effect hook: `injectAttribute` mutates the call target
 * variable's type (the `setAttribute` pattern), `captureBody` records the first
 * argument as the request body. Every dispatch is logged so a test can assert
 * the hook fires exactly once per call node.
 */
class SideEffectMethodExtension extends MethodCallExtension
{
    /** @var list<string> */
    public static array $dispatchLog = [];

    public function handleSideEffect(MethodCallContext $context): void
    {
        self::$dispatchLog[] = $context->methodName;

        $var = $context->node->var;

        if ($context->methodName === 'injectAttribute' && $var instanceof Variable && is_string($var->name)) {
            $context->mutateVar($var->name, ['injected' => new StringType]);
        }

        if ($context->methodName === 'captureBody') {
            $context->setRequestType($context->argTypes->get(0)->unwrapType($context->scope->config));
        }
    }
}
