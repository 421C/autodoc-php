<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\CacheableRecord;
use AutoDoc\Tests\TestProject\Entities\CachedPlanet;
use AutoDoc\Tests\TestProject\Entities\CachedSpecialist;
use AutoDoc\Tests\TestProject\Entities\LabelledRecord;
use AutoDoc\Tests\TestProject\Entities\RecordConnector;
use AutoDoc\Tests\TestProject\Entities\RecordWithPayload;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class NullsafeAccessTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aNullsafeAccessThatNoMemberResolvesIsUnknownOrNull(): void
    {
        $closures = [
            /** @phpstan-ignore property.notFound */
            'propertyOfAnInterface' => fn (?CacheableRecord $record) => $record?->icon,
            /** @phpstan-ignore property.notFound */
            'propertyOfAnObject' => fn (?object $value) => $value?->doesNotExist,
            /** @phpstan-ignore property.notFound */
            'undeclaredPropertyOfAClass' => fn (?CachedSpecialist $specialist) => $specialist?->doesNotExist,
            'mixedProperty' => fn (?RecordWithPayload $record) => $record?->payload,
            'mixedMethod' => fn (?LabelledRecord $record) => $record?->label(),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), $this->closureReturnTypeScript(fn (mixed $value) => $value)),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aNullsafeAccessKeepsTheTypesTheMembersResolve(): void
    {
        $closures = [
            'declaredProperty' => fn (?CachedSpecialist $specialist) => $specialist?->icon,
            /** @phpstan-ignore property.notFound */
            'propertyOfSomeMembers' => fn (CachedSpecialist|CachedPlanet|null $record) => $record?->icon,
            'propertyOfABoundTemplate' => fn () => new RecordConnector(CachedSpecialist::class)->find()?->icon,
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), 'string|null'),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aPlainAccessThatNoMemberResolvesIsUnknown(): void
    {
        $closures = [
            /** @phpstan-ignore property.notFound */
            'propertyOfAnInterface' => fn (CacheableRecord $record) => $record->icon,
            /** @phpstan-ignore property.nonObject */
            'propertyOfANullableInterface' => fn (?CacheableRecord $record) => $record->icon,
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), 'unknown'),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    private function closureReturnTypeScript(Closure $closure): string
    {
        $config = self::loadConfig();
        $scope = new Scope($config);

        $type = new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return new TypeConverter()->convertToTypeScriptType($type, new TypeScriptRenderContext($scope, $config->getTypeScriptConfig()));
    }
}
