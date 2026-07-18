<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

enum ScopeEventType
{
    /**
     * Full variable assignment ($x = ...)
     */
    case Assign;

    /**
     * Mutates a property or array key, optionally on a nested value selected by
     * `mutationPath`.
     */
    case Mutate;

    /** Type narrowing from a condition (instanceof, !== null, etc.) */
    case Narrow;

    /**
     * Narrows a statically known property or array-key path rooted at a variable.
     */
    case NarrowAttribute;
}
