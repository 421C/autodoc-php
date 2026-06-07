<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

/**
 * A type that is never reached: it has no possible values. Produced by narrowing
 * that proves a branch is unreachable (a contradictory condition). A union
 * absorbs it (`T | never = T`), while an intersection collapses to it
 * (`T & never = never`) — see Type::unwrapType().
 */
class NeverType extends Type
{
    public function toSchema(Config $config): array
    {
        // An empty `enum` validates no instance, matching a value that never occurs.
        return [
            'enum' => [],
        ];
    }
}
