<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\Tests\TestProject\Entities\GroupHolder;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * Property PHPDoc types must resolve against the declaring class's
 * name-resolution context, not the calling scope. The resolved map is
 * memoized on the globally cached PhpClass, so a wrong-context first
 * toucher would poison every later consumer.
 */
final class ClassPropertyResolutionContextTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function propertyTypesResolveInTheDeclaringClassContext(): void
    {
        $config = self::loadConfig();
        $scope = new Scope($config);

        $groupsType = $scope->getPhpClass(GroupHolder::class)->getProperty('groups');

        self::assertNotNull($groupsType);

        $outerArray = $groupsType->unwrapType($config);
        self::assertInstanceOf(ArrayType::class, $outerArray);

        $innerArray = $outerArray->itemType?->unwrapType($config);
        self::assertInstanceOf(ArrayType::class, $innerArray);

        $element = $innerArray->itemType?->unwrapType($config);
        self::assertInstanceOf(ObjectType::class, $element);
        self::assertSame(SimpleClass::class, $element->className);
    }

    #[Test]
    public function propertyReadInAnUnrelatedScopeDoesNotPoisonLaterMutationReads(): void
    {
        $this->getClosureReturnSchema(function (GroupHolder $holder): mixed {
            // @phpstan-ignore property.notFound
            return $holder->undeclared;
        });

        $schema = $this->getClosureReturnSchema(function (GroupHolder $holder): mixed {
            // @phpstan-ignore property.notFound
            $holder->groups[0][1]->flag = true;

            return $holder->groups[0][1];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'n' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
                'flag' => [
                    'type' => 'boolean',
                ],
            ],
            'required' => [
                'n',
                'flag',
            ],
        ], $schema, 'closure', 'return');
    }

    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $scope = new Scope($config);

        $type = new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->getReturnType(usePhpDocIfAvailable: false);

        return $type->toSchema($config);
    }
}
