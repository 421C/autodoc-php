<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @template T
 */
class GenericClass
{
    public function __construct(
        /**
         * @var T
         */
        public $data,
    ) {}
}
