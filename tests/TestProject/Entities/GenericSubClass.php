<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @extends GenericClass<int>
 */
class GenericSubClass extends GenericClass
{
    public function __construct(
        public int $n,
    ) {
        parent::__construct($n);
    }
}
