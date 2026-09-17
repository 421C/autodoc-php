<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer\DocBlock;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\IntRangeDocTypesHolder;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntRangeDocTypeTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function docBlockIntegerRangesBecomeSchemaBounds(): void
    {
        $config = self::loadConfig();

        $scope = new Scope($config);

        $schema = $scope->getPhpClass(IntRangeDocTypesHolder::class)
            ->getMethod('docTypes')
            ->getReturnType()
            ->toSchema($config);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'bounded' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'openMinimum' => [
                    'type' => 'integer',
                    'maximum' => 50,
                ],
                'openMaximum' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'negative' => [
                    'type' => 'integer',
                    'minimum' => -5,
                    'maximum' => 5,
                ],
                'constantBound' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => IntRangeDocTypesHolder::MAX_PAGE_SIZE,
                ],
                'unbounded' => [
                    'type' => 'integer',
                ],
                'nullable' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'alias' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'nested' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 3,
                    ],
                ],
            ],
            'required' => [
                'bounded',
                'openMinimum',
                'openMaximum',
                'negative',
                'constantBound',
                'unbounded',
                'nullable',
                'alias',
                'nested',
            ],
        ], $schema, 'docTypes', 'return');
    }
}
