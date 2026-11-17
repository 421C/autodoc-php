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


    #[Test]
    public function aMapWithANarrowedKeyDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","properties":{"alice":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapWithAKeyNarrowedByATypeCheckDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::intsOrStringsByName();

            if (is_string($values['alice'])) {
                return $values;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","properties":{"alice":{"type":"string"}},"additionalProperties":{"type":["integer","string"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapOfUnknownValuesWithANarrowedKeyAllowsAnyOtherEntry(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::mixedByName();

            if (is_int($values['alice'])) {
                return $values;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","properties":{"alice":{"type":"integer"}},"additionalProperties":true}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapWithTwoNarrowedKeysDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null && $scores['bob'] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","properties":{"alice":{"type":"integer"},"bob":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapWithAKeyKnownToExistDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if (array_key_exists('alice', $scores)) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","properties":{"alice":{"type":["integer","null"]}},"additionalProperties":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapOfShapesWithANarrowedEntryDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $users = OpenArrayShapeTest::usersByName();

            if ($users['admin']['email'] !== null) {
                return $users;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object",'
                . '"properties":{"admin":{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":"string"}},"required":["id","email"]}},'
                . '"additionalProperties":{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":["string","null"]}},"required":["id","email"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapNestedInAShapeDocumentsItsOtherEntriesAfterAKeyIsNarrowed(): void
    {
        $closure = function () {
            $report = OpenArrayShapeTest::report();

            if ($report['scores']['alice'] !== null) {
                return $report;
            }

            return null;
        };

        $this->assertSame(
            '{"type":["object","null"],'
                . '"properties":{"scores":{"type":"object","properties":{"alice":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}},"total":{"type":"integer"}},'
                . '"required":["scores","total"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function anIntKeyedMapWithANarrowedKeyDocumentsItsOtherEntries(): void
    {
        $closure = function (?int $first, ?int $second) {
            $scores = array_map(fn (?int $score) => $score, [1 => $first, 2 => $second]);

            if ($scores[1] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","properties":{"1":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aShapeWithANarrowedKeyAllowsNoOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::closedScores();

            if ($scores['alice'] !== null) {
                return $scores;
            }

            return null;
        };

        $this->assertSame(
            '{"type":["object","null"],"properties":{"alice":{"type":"integer"},"bob":{"type":"integer"}},"required":["alice","bob"]}',
            $this->closureReturnSchema($closure),
        );
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


    /**
     * @return array<string, int|string>
     */
    private static function intsOrStringsByName(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array<string, mixed>
     */
    private static function mixedByName(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array<string, array{id: int, email: string|null}>
     */
    private static function usersByName(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{scores: array<string, int|null>, total: int}
     */
    private static function report(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array{alice: int|null, bob: int}
     */
    private static function closedScores(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }
}
