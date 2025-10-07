<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Traits;

use AutoDoc\Tests\TestProject\Entities\ClassWithTypeAliasDefinition;

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
}
