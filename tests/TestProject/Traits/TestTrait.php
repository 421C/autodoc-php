<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Traits;

use AutoDoc\Tests\TestProject\Entities\ClassWithTypeAliasDefinition;
use AutoDoc\Tests\TestProject\Entities\Rocket;

/**
 * @phpstan-import-type TestTypeAlias from ClassWithTypeAliasDefinition
 */
trait TestTrait
{
    /**
     * @return TestTypeAlias
     */
    private function methodFromTrait(): array
    {
        return [
            'a' => 1,
            'b' => '2',
        ];
    }

    /**
     * @return Rocket[]
     */
    private function methodFromTraitThatReturnsRockets(): array
    {
        return [new Rocket];
    }

    /**
     * @return array<Rocket[]>
     *
     * @phpstan-ignore return.missing
     */
    private function methodFromTraitThatReturnsArraysOfRocketsWithPhpDocOnly() {}
}
