<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\FuncCallContext;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Extensions\FuncCallExtension;

/**
 * Overrides a function that the built-in `ArrayFuncCall` extension also
 * handles, to prove configured extensions take precedence over built-ins.
 */
class ArrayMapOverrideExtension extends FuncCallExtension
{
    public function getReturnType(FuncCallContext $context): ?Type
    {
        if ($context->functionName === 'array_map') {
            return new StringType('overridden by extension');
        }

        return null;
    }
}
