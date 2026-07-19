<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\DataTypes\Type;
use AutoDoc\Route;

class ResolvedTypeScriptRoute
{
    /**
     * @param 'Request'|'Response' $declarationSuffix
     */
    public function __construct(
        public readonly Route $route,
        public readonly Type $type,
        public readonly string $declarationSuffix,
    ) {}
}
