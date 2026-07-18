<?php declare(strict_types=1);

namespace AutoDoc\Extensions\BuiltIn;

use AutoDoc\Extensions\FuncCallContext;
use AutoDoc\Analyzer\Narrowing\IsType;
use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\Analyzer\Narrowing\NotNull;
use AutoDoc\Analyzer\Narrowing\NotType;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\Extensions\FuncCallExtension;
use PhpParser\Node;


/**
 * Models the type-narrowing semantics of PHP's type-check functions
 * (`is_string`, `is_int`, `is_array`, ...). When such a function is used as a
 * condition, its single argument is narrowed to (or away from) the asserted
 * type. The same mapping is reused by `ArrayFuncCall` for callback-based
 * `array_filter($items, 'is_string')`.
 */
class TypeCheckFuncCall extends FuncCallExtension
{
    public function narrowTypeFromCondition(FuncCallContext $context, bool $negated): void
    {
        if ($context->functionName === null || count($context->node->args) !== 1) {
            return;
        }

        $arg = $context->node->args[0];

        if (! $arg instanceof Node\Arg) {
            return;
        }

        $narrowing = self::narrowingForFunction($context->functionName, $negated);

        if ($narrowing !== null) {
            $context->narrowExpressionType($arg->value, $narrowing);
        }
    }


    /**
     * Map a PHP type-check function name to the narrowing it asserts about its
     * single argument. Returns null when the name isn't a supported type-check
     * function, or when its negation can't be expressed as a single narrowing.
     */
    public static function narrowingForFunction(string $funcName, bool $negated = false): ?Narrowing
    {
        $funcName = strtolower(ltrim($funcName, '\\'));

        $type = match ($funcName) {
            'is_array' => new ArrayType,
            'is_string' => new StringType,
            'is_int', 'is_integer', 'is_long' => new IntegerType,
            'is_float', 'is_double' => new FloatType,
            'is_numeric' => new NumberType,
            'is_bool' => new BoolType,
            'is_null' => new NullType,
            'is_object' => new ObjectType,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        if (! $negated) {
            return new IsType($type);
        }

        // Negated is_null is equivalent to !== null.
        if ($type instanceof NullType) {
            return new NotNull;
        }

        // is_numeric matches several types (int|float|numeric-string), so its
        // negation can't be expressed as removing a single type class.
        if ($type instanceof NumberType) {
            return null;
        }

        return new NotType($type);
    }
}
