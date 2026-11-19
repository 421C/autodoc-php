<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\TestProject\Entities\CacheableRecord;
use AutoDoc\Tests\TestProject\Entities\CachedPlanet;
use AutoDoc\Tests\TestProject\Entities\CachedSpecialist;
use AutoDoc\Tests\TestProject\Entities\IntersectionBoundRecordConnector;
use AutoDoc\Tests\TestProject\Entities\RecordConnector;
use AutoDoc\Tests\TestProject\Entities\UnboundRecordConnector;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class ClassTemplateBindingTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aClassTemplateBoundAtTheReceiverReachesItsMethods(): void
    {
        $closures = [
            'constructorClassString' => fn () => new RecordConnector(CachedSpecialist::class)->find(),
            'variable' => function () {
                $connector = new RecordConnector(CachedSpecialist::class);

                return $connector->find();
            },
            'varTagGeneric' => function () {
                /** @var \AutoDoc\Tests\TestProject\Entities\RecordConnector<\AutoDoc\Tests\TestProject\Entities\CachedSpecialist> $connector */
                $connector = ClassTemplateBindingTest::untypedValue();

                return $connector->find();
            },
            'staticReturnOfATraitMethod' => fn () => CachedSpecialist::connector()->find(),
            'withoutABound' => fn () => new UnboundRecordConnector(CachedSpecialist::class)->find(),
            'intersectionBound' => fn () => new IntersectionBoundRecordConnector(CachedSpecialist::class)->find(),
            'nullsafe' => fn (bool $flag) => ($flag ? new RecordConnector(CachedSpecialist::class) : null)?->find(),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), $this->closureReturnSchema(fn (?CachedSpecialist $specialist) => $specialist)),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aUnionOfReceiversBindsTheUnionOfTheirTemplateValues(): void
    {
        $closure = fn (bool $flag) => ($flag ? new RecordConnector(CachedSpecialist::class) : new RecordConnector(CachedPlanet::class))->find();

        $this->assertSame(
            $this->closureReturnSchema(fn (CachedSpecialist|CachedPlanet|null $record) => $record),
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aClassTemplateWithoutAValueFallsBackToItsBound(): void
    {
        $closure = fn (RecordConnector $connector) => $connector->find();

        $this->assertSame(
            $this->closureReturnSchema(fn (?CacheableRecord $record) => $record),
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aClassTemplateWithoutAValueOrABoundIsUnknown(): void
    {
        $closure = fn (UnboundRecordConnector $connector) => $connector->find();

        $this->assertSame('{"type":["string","null"]}', $this->closureReturnSchema($closure));
    }


    private function closureReturnSchema(Closure $closure): string
    {
        $config = self::loadConfig();

        $type = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return json_encode(SchemaJson::castPropertiesToObjects($type->toSchema($config)), JSON_THROW_ON_ERROR);
    }


    private static function untypedValue(): mixed
    {
        return json_decode('null');
    }
}
