<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class OpenArrayShapeTest extends TestCase
{
    use LoadsConfig;

    private const string NULLABLE_INTEGER_LIST = '{"type":"array","items":{"type":["integer","null"]}}';

    #[Test]
    public function valuesOfAMapWithANarrowedKeyIncludeItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null) {
                return array_values($scores);
            }

            return [];
        };

        $this->assertSame(self::NULLABLE_INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function keysOfAMapWithANarrowedKeyIncludeItsOtherKeys(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null) {
                return array_keys($scores);
            }

            return [];
        };

        $this->assertSame('{"type":"array","items":{"type":"string"}}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function iteratingAMapWithANarrowedKeyYieldsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null) {
                foreach ($scores as $score) {
                    return $score;
                }
            }

            return 0;
        };

        $this->assertSame('{"type":["integer","null"]}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function mappingAListWithANarrowedIndexKeepsTheList(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[5] !== null) {
                return array_map(fn (?int $score) => $score, $scores);
            }

            return [];
        };

        $this->assertSame(self::NULLABLE_INTEGER_LIST, $this->closureReturnSchema($closure));
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


    /**
     * @return array<string, int|null>
     */
    private static function nullableIntsByName(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<int|null>
     */
    private static function nullableInts(): array
    {
        return json_decode('[]', true);
    }
}
