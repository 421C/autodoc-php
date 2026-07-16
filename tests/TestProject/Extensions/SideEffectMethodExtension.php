<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\MethodCallContext;
use AutoDoc\DataTypes\StringType;
use AutoDoc\Extensions\MethodCallExtension;
use PhpParser\Node\Expr\Variable;

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

        if ($context->methodName === 'injectNested') {
            $context->mutateExpression($var, ['tagged' => new StringType]);
        }

        if ($context->methodName === 'captureBody') {
            $context->setRequestType($context->argTypes->get(0)->unwrapType($context->scope->config));
        }
    }
}
