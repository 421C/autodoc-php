<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;

class PhpVariable
{
    public function __construct(
        /**
         * @var array<int, array{Type, int}>
         */
        public array $assignments = [],
    ) {}
}
