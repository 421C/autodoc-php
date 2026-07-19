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

    /**
     * Narrows a variable or a statically known property or array-key path within it.
     */
    case Narrow;
}
