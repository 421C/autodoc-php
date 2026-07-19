<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;

/**
 * @phpstan-type AutoDocTagOptions array{
 *     omit?: string[],
 *     only?: string[],
 *     from?: class-string,
 *     with?: array<int|string, Type>,
 *     mode?: string,
 *     as?: string,
 * }
 */
class ParsedAutoDocTag
{
    /**
     * @param AutoDocTagOptions $options
     */
    public function __construct(
        public readonly Scope $scope,
        public readonly string $value,
        public readonly array $options = [],
    ) {}
}
