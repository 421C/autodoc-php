<?php declare(strict_types=1);

namespace AutoDoc\Tests\Attributes;

use Attribute;

#[Attribute]
class ExpectedOperationSchema
{
    public function __construct(
        /**
         * @var array<string, mixed>
         */
        public array $schema,
    ) {}
}
