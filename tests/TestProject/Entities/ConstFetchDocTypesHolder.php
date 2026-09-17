<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use AutoDoc\Tests\TestProject\Entities\StateEnum as State;

use const AutoDoc\Tests\TestProject\Support\DEFAULT_STATUS;

class ConstFetchDocTypesHolder
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ARCHIVED = 'archived';

    public const int LIMIT = 10;

    /**
     * @return array{
     *     classConstant: self::STATUS_ACTIVE,
     *     qualifiedConstant: StateEnum::DEFAULT_VALUE,
     *     wildcard: self::STATUS_*,
     *     importedConstant: DEFAULT_STATUS,
     *     globalConstant: \PHP_INT_SIZE,
     *     enumCase: State::One,
     *     enumWildcard: State::*,
     *     className: State::class,
     * }
     */
    public function docTypes(): mixed
    {
        return [
            'classConstant' => self::STATUS_ACTIVE,
            'qualifiedConstant' => StateEnum::DEFAULT_VALUE,
            'wildcard' => self::STATUS_ARCHIVED,
            'importedConstant' => DEFAULT_STATUS,
            'globalConstant' => PHP_INT_SIZE,
            'enumCase' => State::One,
            'enumWildcard' => State::Two,
            'className' => State::class,
        ];
    }
}
