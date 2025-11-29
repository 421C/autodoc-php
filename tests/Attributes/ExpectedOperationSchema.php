<?php declare(strict_types=1);

namespace AutoDoc\Tests\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class ExpectedOperationSchema
{
    public function __construct(
        public string $testMethod,

        /**
         * @var array<string, mixed>
         */
        public array $schema,
    ) {}
}
