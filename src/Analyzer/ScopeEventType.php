<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

enum ScopeEventType
{
    /** Full variable assignment ($x = ...) */
    case Assign;

    /** Attribute mutation ($x['key'] = ... or $x->prop = ...) */
    case Mutate;

    /** Type narrowing from a condition (instanceof, !== null, etc.) */
    case Narrow;
}
