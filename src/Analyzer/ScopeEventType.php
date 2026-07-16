<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

enum ScopeEventType
{
    /** Full variable assignment ($x = ...) */
    case Assign;

    /**
     * Mutates a property or array key, optionally on a nested value selected by
     * `mutationPath`.
     */
    case Mutate;

    /** Type narrowing from a condition (instanceof, !== null, etc.) */
    case Narrow;

    /**
     * Type narrowing of a literal attribute path on a variable's type from a condition.
     */
    case NarrowAttribute;
}
