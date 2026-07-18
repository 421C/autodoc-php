<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;


final readonly class NarrowingFact
{
    public function __construct(
        public Target $target,
        public Narrowing $narrowing,
    ) {}
}
