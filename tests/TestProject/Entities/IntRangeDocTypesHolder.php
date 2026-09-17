<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class IntRangeDocTypesHolder
{
    public const int MAX_PAGE_SIZE = 200;

    /**
     * @return array{
     *     bounded: int<0, 100>,
     *     openMinimum: int<min, 50>,
     *     openMaximum: int<1, max>,
     *     negative: int<-5, 5>,
     *     constantBound: int<1, self::MAX_PAGE_SIZE>,
     *     unbounded: int<min, max>,
     *     nullable: int<0, 100>|null,
     *     alias: positive-int,
     *     nested: int<1, 3>[],
     * }
     */
    public function docTypes(): mixed
    {
        return [
            'bounded' => 50,
            'openMinimum' => 10,
            'openMaximum' => 10,
            'negative' => 0,
            'constantBound' => 25,
            'unbounded' => 1,
            'nullable' => null,
            'alias' => 1,
            'nested' => [1, 2],
        ];
    }
}
