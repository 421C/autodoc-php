<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\DocBlock;

use AutoDoc\DataTypes\Type;
use Closure;


final readonly class PhpDocBinding
{
    /**
     * @param array<string, ?Type> $templateTypes
     * @param ?Closure(string): Type $argumentTypeResolver
     */
    public function __construct(
        public array $templateTypes = [],
        public ?Closure $argumentTypeResolver = null,
    ) {}
}
