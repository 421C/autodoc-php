<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\Scope;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\TestProject\Entities\InheritedPropertyChild;
use AutoDoc\Tests\TestProject\Entities\InheritedPropertyMiddle;
use AutoDoc\Tests\TestProject\Entities\PropertyTypeSource;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * PhpClass caches property types for the whole run, an event hands one Type
 * instance to every later read of its variable, and ArgumentList memoizes the
 * arguments of a call, so deriving a type must leave the type it reads unchanged.
 */
final class SharedTypeIsolationTest extends TestCase
{
    use LoadsConfig;

    protected function setUp(): void
    {
        PhpClass::$cache = [];
    }


    #[Test]
    public function aSubclassPropertyDocblockLeavesTheInheritedPropertyUnchanged(): void
    {
        $readMiddle = fn (InheritedPropertyMiddle $middle) => $middle;
        $declaredSchema = $this->closureReturnSchema($readMiddle);

        $childSchema = $this->closureReturnSchema(fn (InheritedPropertyChild $child) => $child);

        $this->assertStringContainsString('Receipt is available', $childSchema);
        $this->assertSame($declaredSchema, $this->closureReturnSchema($readMiddle));
    }


    #[Test]
    public function arrayFunctionsLeaveThePropertyTheyReadUnchanged(): void
    {
        $this->assertReadersLeaveTheSourceUnchanged([
            'array_flip' => fn (PropertyTypeSource $source) => array_flip($source->map),
            'array_values' => fn (PropertyTypeSource $source) => array_values($source->map),
            'array_values of literal entries' => fn (PropertyTypeSource $source) => array_values($source->literalShape),
            'array_keys' => fn (PropertyTypeSource $source) => array_keys($source->shape),
            'array_map' => fn (PropertyTypeSource $source) => array_map(fn (int|string $value) => $value, $source->shape),
            'array_merge' => fn (PropertyTypeSource $source) => array_merge($source->shape, ['extra' => true]),
            'array_filter' => fn (PropertyTypeSource $source) => array_filter($source->list, fn (int $value) => $value > 0),
            'end' => fn (PropertyTypeSource $source) => end($source->shape),
        ]);
    }


    #[Test]
    public function foreachLeavesTheArrayItIteratesUnchanged(): void
    {
        $this->assertReadersLeaveTheSourceUnchanged([
            'value' => function (PropertyTypeSource $source) {
                foreach ($source->shape as $value) {
                    $lastValue = $value;
                }

                return $lastValue;
            },
            'key' => function (PropertyTypeSource $source) {
                foreach ($source->shape as $key => $value) {
                    $lastKey = $key;
                }

                return $lastKey;
            },
        ]);
    }


    #[Test]
    public function unionsLeaveTheirMembersUnchanged(): void
    {
        $this->assertReadersLeaveTheSourceUnchanged([
            'shape with another entry type' => fn (PropertyTypeSource $source, bool $flag) => $flag ? $source->shape : ['id' => 'text', 'name' => 'y'],
            'literal with an unknown type' => fn (PropertyTypeSource $source, bool $flag, mixed $unknown) => $flag ? $source->mode : $unknown,
        ]);
    }


    #[Test]
    public function readingAnArrayFunctionResultTwiceGivesTheSameType(): void
    {
        $closures = [
            'array_flip' => function () {
                $scores = ['a' => 1, 'b' => 2];
                $flipped = array_flip($scores);

                return ['first' => $flipped, 'second' => $flipped];
            },
            'array_merge' => function () {
                $base = ['id' => 1];
                $merged = array_merge($base, ['name' => 'x']);

                return ['first' => $merged, 'second' => $merged];
            },
        ];

        $entries = array_map($this->returnedEntrySchemas(...), $closures);

        $this->assertSame(
            array_map(fn (array $entry) => $entry['first'], $entries),
            array_map(fn (array $entry) => $entry['second'], $entries),
        );
    }


    #[Test]
    public function anArrayFunctionLeavesTheTypeASortGaveTheVariable(): void
    {
        $closures = [
            'array_filter' => function (callable $callback) {
                $codes = ['x', 'y'];
                sort($codes);
                $filtered = array_filter($codes, $callback);

                return ['derived' => $filtered, 'source' => $codes];
            },
            'array_flip' => function () {
                $codes = ['x', 'y'];
                sort($codes);
                $flipped = array_flip($codes);

                return ['derived' => $flipped, 'source' => $codes];
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['x', 'y']]]),
            array_map(fn (Closure $closure) => $this->returnedEntrySchemas($closure)['source'], $closures),
        );
    }


    /**
     * @param array<string, Closure> $readers
     */
    private function assertReadersLeaveTheSourceUnchanged(array $readers): void
    {
        $readSource = fn (PropertyTypeSource $source) => $source;
        $declaredSchema = $this->closureReturnSchema($readSource);
        $schemasAfterReading = [];

        foreach ($readers as $name => $reader) {
            PhpClass::$cache = [];
            $this->closureReturnSchema($reader);
            $schemasAfterReading[$name] = $this->closureReturnSchema($readSource);
        }

        $this->assertSame(array_fill_keys(array_keys($readers), $declaredSchema), $schemasAfterReading);
    }


    /**
     * @return array<mixed>
     */
    private function returnedEntrySchemas(Closure $closure): array
    {
        $schema = json_decode($this->closureReturnSchema($closure), associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($schema);

        $entries = $schema['properties'] ?? null;
        $this->assertIsArray($entries);

        return $entries;
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
}
