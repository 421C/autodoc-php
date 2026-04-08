<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\Config;
use AutoDoc\DataTypes\Type;

/**
 * Marker type used by the type narrower to indicate "not null".
 * This is applied during variable resolution to strip NullType from unions.
 */
class NonNullNarrowedType extends Type
{
    public function toSchema(?Config $config = null): array
    {
        return [];
    }
}
