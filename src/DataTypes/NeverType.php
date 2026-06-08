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
    public function __construct(
        /**
         * Debug context for `never` produced by an impossible intersection.
         * Empty for a plain `never` (e.g. a `never` return).
         *
         * @var Type[]
         */
        public array $conflictingTypes = [],
        bool $required = false,
    ) {
        $this->required = $required;
    }

    public function toSchema(Config $config): array
    {
        if ($config->data['intersections']['render_empty_as_unknown'] ?? true) {
            return new UnknownType($this->description)->toSchema($config);
        }

        // An empty `enum` validates no instance, matching a value that never occurs.
        return [
            'enum' => [],
        ];
    }
}
