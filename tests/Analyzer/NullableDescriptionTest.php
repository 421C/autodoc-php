<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\ClassWithDocumentedNullableProperty;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use RuntimeException;

final class NullableDescriptionTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aTernaryWithNullKeepsTheNullablePropertyDescription(): void
    {
        $this->assertSame(
            [
                'type' => ['string', 'null'],
                'description' => 'Creation date (UTC).',
            ],
            $this->getClosureReturnSchema(
                fn (ClassWithDocumentedNullableProperty $entity, bool $flag) => $flag ? $entity->date : null,
            ),
        );
    }


    #[Test]
    public function aVariableAssignedInABranchKeepsTheNullablePropertyDescription(): void
    {
        $this->assertSame(
            [
                'type' => ['string', 'null'],
                'description' => 'Creation date (UTC).',
            ],
            $this->getClosureReturnSchema(function (ClassWithDocumentedNullableProperty $entity, bool $flag) {
                $date = null;

                if ($flag) {
                    $date = $entity->date;
                }

                return $date;
            }),
        );
    }


    #[Test]
    public function aNullCheckKeepsTheNarrowedPropertyDescription(): void
    {
        $this->assertSame(
            [
                'type' => 'string',
                'description' => 'Creation date (UTC).',
            ],
            $this->getClosureReturnSchema(function (ClassWithDocumentedNullableProperty $entity) {
                if ($entity->date === null) {
                    throw new RuntimeException;
                }

                return $entity->date;
            }),
        );
    }


    #[Test]
    public function aCoalesceThrowKeepsTheNarrowedPropertyDescription(): void
    {
        $this->assertSame(
            [
                'type' => 'string',
                'description' => 'Creation date (UTC).',
            ],
            $this->getClosureReturnSchema(
                fn (ClassWithDocumentedNullableProperty $entity) => $entity->date ?? throw new RuntimeException,
            ),
        );
    }


    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure): array
    {
        $config = self::loadConfig();

        $type = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return $type->toSchema($config);
    }
}
