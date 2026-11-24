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
use stdClass;

final class ByReferenceArrayFunctionTest extends TestCase
{
    use LoadsConfig;

    private const string INTEGER_LIST = '{"type":"array","items":{"type":"integer"}}';
    private const string ID_AND_NAME = '{"type":"object","properties":{"id":{"type":"integer","const":1},"name":{"type":"string","const":"x"}},"required":["id","name"]}';

    #[Test]
    public function arrayPopRemovesTheLastEntry(): void
    {
        $closures = [
            'nonEmptyList' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_pop($ids);

                return $ids;
            },
            'stringKeyedShape' => function () {
                $user = ['id' => 1, 'name' => 'x'];
                array_pop($user);

                return $user;
            },
            'intKeyedShape' => function () {
                $names = [5 => 'x', 6 => 'y'];
                array_pop($names);

                return $names;
            },
            'shapeWithOptionalEntry' => function (bool $flag) {
                $row = ['a' => 1];

                if ($flag) {
                    $row['b'] = 2;
                }

                array_pop($row);

                return $row;
            },
            'nestedKey' => function () {
                $data = ['ids' => ByReferenceArrayFunctionTest::nonEmptyInts(), 'count' => 0];
                array_pop($data['ids']);

                return $data;
            },
            'property' => function () {
                $holder = new stdClass;
                $holder->ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_pop($holder->ids);

                return $holder;
            },
        ];

        $this->assertSame(
            [
                'nonEmptyList' => self::INTEGER_LIST,
                'stringKeyedShape' => '{"type":"object","properties":{"id":{"type":"integer","const":1}},"required":["id"]}',
                'intKeyedShape' => '{"type":"object","properties":{"5":{"type":"string","const":"x"}},"required":["5"]}',
                'shapeWithOptionalEntry' => '{"type":"object","properties":{"a":{"type":"integer","const":1},"b":{"type":"integer","const":2}}}',
                'nestedKey' => '{"type":"object","properties":{"ids":' . self::INTEGER_LIST . ',"count":{"type":"integer","const":0}},"required":["ids","count"]}',
                'property' => '{"type":"object","properties":{"ids":' . self::INTEGER_LIST . '},"required":["ids"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayPopKeepsTheKeysOfAnArrayThatMayNotBeAList(): void
    {
        $popped = function (int $id, string $name) {
            $namesById = [$id => $name];
            array_pop($namesById);

            return $namesById;
        };

        $untouched = function (int $id, string $name) {
            $namesById = [$id => $name];

            return $namesById;
        };

        $this->assertSame($this->closureReturnSchema($untouched), $this->closureReturnSchema($popped));
    }


    #[Test]
    public function arrayShiftRemovesTheFirstEntryAndRenumbersIntKeys(): void
    {
        $closures = [
            'nonEmptyList' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_shift($ids);

                return $ids;
            },
            'stringKeyedShape' => function () {
                $user = ['id' => 1, 'name' => 'x'];
                array_shift($user);

                return $user;
            },
            'intKeyedShape' => function () {
                $names = [5 => 'x', 6 => 'y', 'k' => 'z'];
                array_shift($names);

                return $names;
            },
            'arrayThatMayNotBeAList' => function (int $id, string $name) {
                $namesById = [$id => $name];
                array_shift($namesById);

                return $namesById;
            },
            'map' => function () {
                $scores = ByReferenceArrayFunctionTest::scoresByName();
                array_shift($scores);

                return $scores;
            },
            'nestedKey' => function () {
                $data = ['ids' => ByReferenceArrayFunctionTest::nonEmptyInts(), 'count' => 0];
                array_shift($data['ids']);

                return $data;
            },
        ];

        $this->assertSame(
            [
                'nonEmptyList' => self::INTEGER_LIST,
                'stringKeyedShape' => '{"type":"object","properties":{"name":{"type":"string","const":"x"}},"required":["name"]}',
                'intKeyedShape' => '{"type":"object","properties":{"0":{"type":"string","const":"y"},"k":{"type":"string","const":"z"}},"required":["0","k"]}',
                'arrayThatMayNotBeAList' => '{"type":"array","items":{"type":"string"}}',
                'map' => '{"type":"object","additionalProperties":{"type":"integer"}}',
                'nestedKey' => '{"type":"object","properties":{"ids":' . self::INTEGER_LIST . ',"count":{"type":"integer","const":0}},"required":["ids","count"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayPopAndArrayShiftStillReturnTheRemovedEntry(): void
    {
        $closures = [
            'array_pop' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();

                return array_pop($ids);
            },
            'array_shift' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();

                return array_shift($ids);
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":["integer","null"]}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arraySpliceMayRemoveEntriesAndAddTheReplacement(): void
    {
        $closures = [
            'noReplacement' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_splice($ids, 1);

                return $ids;
            },
            'arrayReplacement' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_splice($ids, 1, 0, ['a', 'b']);

                return $ids;
            },
            'scalarReplacement' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_splice($ids, 0, 1, 'a');

                return $ids;
            },
            'namedReplacement' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_splice($ids, 0, replacement: ['a']);

                return $ids;
            },
            'arrayThatMayNotBeAList' => function (int $id, string $name) {
                $namesById = [$id => $name];
                array_splice($namesById, 0, 0, ['a']);

                return $namesById;
            },
            'nestedKey' => function () {
                $data = ['ids' => ByReferenceArrayFunctionTest::nonEmptyInts(), 'count' => 0];
                array_splice($data['ids'], 1, 0, ['a']);

                return $data;
            },
        ];

        $this->assertSame(
            [
                'noReplacement' => self::INTEGER_LIST,
                'arrayReplacement' => '{"type":"array","items":{"anyOf":[{"type":"string","enum":["a","b"]},{"type":"integer"}]}}',
                'scalarReplacement' => '{"type":"array","items":{"anyOf":[{"type":"string","const":"a"},{"type":"integer"}]}}',
                'namedReplacement' => '{"type":"array","items":{"anyOf":[{"type":"string","const":"a"},{"type":"integer"}]}}',
                'arrayThatMayNotBeAList' => '{"type":"array","items":{"type":"string"}}',
                'nestedKey' => '{"type":"object","properties":{"ids":{"type":"array","items":{"anyOf":[{"type":"string","const":"a"},{"type":"integer"}]}},"count":{"type":"integer","const":0}},"required":["ids","count"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arraySpliceDropsTheKeysOfTheReplacement(): void
    {
        $closures = [
            'shapeReplacement' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_splice($ids, 1, 0, ['k' => 'a']);

                return $ids;
            },
            'mapReplacement' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();
                array_splice($ids, 1, 0, ByReferenceArrayFunctionTest::scoresByName());

                return $ids;
            },
        ];

        $this->assertSame(
            [
                'shapeReplacement' => '{"type":"array","items":{"anyOf":[{"type":"string","const":"a"},{"type":"integer"}]}}',
                'mapReplacement' => self::INTEGER_LIST,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arraySpliceReturnsTheRemovedEntries(): void
    {
        $closures = [
            'list' => function () {
                $ids = ByReferenceArrayFunctionTest::nonEmptyInts();

                return array_splice($ids, 1);
            },
            'shape' => function () {
                $user = ['id' => 1, 'name' => 'x'];

                return array_splice($user, 1);
            },
        ];

        $this->assertSame(
            [
                'list' => self::INTEGER_LIST,
                'shape' => '{"type":"object","additionalProperties":{"anyOf":[{"type":"integer","const":1},{"type":"string","const":"x"}]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayWalkKeepsWhatAByReferenceCallbackWritesToEachEntry(): void
    {
        $closures = [
            'byReference' => function () {
                $ids = ByReferenceArrayFunctionTest::ints();
                array_walk($ids, function (int|string &$id) {
                    $id = (string) $id;
                });

                return $ids;
            },
            'byReferenceInABranch' => function () {
                $ids = ByReferenceArrayFunctionTest::ints();
                array_walk($ids, function (int|string &$id) {
                    if ($id > 0) {
                        $id = 'positive';
                    }
                });

                return $ids;
            },
            'eachShapeEntry' => function () {
                $user = ['id' => 1, 'name' => 'x'];
                array_walk($user, function (mixed &$value) {
                    $value = [$value];
                });

                return $user;
            },
            'nestedKey' => function () {
                $data = ['ids' => ByReferenceArrayFunctionTest::ints(), 'count' => 0];
                array_walk($data['ids'], function (int|string &$id) {
                    $id = (string) $id;
                });

                return $data;
            },
        ];

        $this->assertSame(
            [
                'byReference' => '{"type":"array","items":{"type":"string"}}',
                'byReferenceInABranch' => '{"type":"array","items":{"anyOf":[{"type":"string","const":"positive"},{"type":"integer"}]}}',
                'eachShapeEntry' => '{"type":"object","properties":{"id":{"type":"array","items":{"type":"integer","const":1}},"name":{"type":"array","items":{"type":"string","const":"x"}}},"required":["id","name"]}',
                'nestedKey' => '{"type":"object","properties":{"ids":{"type":"array","items":{"type":"string"}},"count":{"type":"integer","const":0}},"required":["ids","count"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayWalkWithoutAByReferenceCallbackKeepsTheEntries(): void
    {
        $closures = [
            'byValue' => function () {
                $ids = ByReferenceArrayFunctionTest::ints();
                array_walk($ids, function (int|string $id) {
                    $id = (string) $id;
                });

                return $ids;
            },
            'namedFunction' => function () {
                $ids = ByReferenceArrayFunctionTest::ints();
                array_walk($ids, 'intval');

                return $ids;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::INTEGER_LIST),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function sortsChangeAnArrayUnderAKeyOrProperty(): void
    {
        $closures = [
            'sortUnderAKey' => function () {
                $data = ['scores' => ByReferenceArrayFunctionTest::scoresByName()];
                sort($data['scores']);

                return $data;
            },
            'sortOnAProperty' => function () {
                $holder = new stdClass;
                $holder->scores = ByReferenceArrayFunctionTest::scoresByName();
                sort($holder->scores);

                return $holder;
            },
            'keyPreservingSortUnderAKey' => function () {
                $data = ['user' => ['id' => 1, 'name' => 'x']];
                asort($data['user']);

                return $data;
            },
        ];

        $this->assertSame(
            [
                'sortUnderAKey' => '{"type":"object","properties":{"scores":' . self::INTEGER_LIST . '},"required":["scores"]}',
                'sortOnAProperty' => '{"type":"object","properties":{"scores":' . self::INTEGER_LIST . '},"required":["scores"]}',
                'keyPreservingSortUnderAKey' => '{"type":"object","properties":{"user":' . self::ID_AND_NAME . '},"required":["user"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function optionalEntriesStayOptionalUnderAKeyOrProperty(): void
    {
        $closures = [
            'arrayPopUnderAKey' => function () {
                $data = ['row' => ByReferenceArrayFunctionTest::rowWithOptionalEntry()];
                array_pop($data['row']);

                return $data;
            },
            'keyPreservingSortUnderAKey' => function () {
                $data = ['row' => ByReferenceArrayFunctionTest::rowWithOptionalEntry()];
                asort($data['row']);

                return $data;
            },
            'arrayWalkUnderAKey' => function () {
                $data = ['row' => ByReferenceArrayFunctionTest::rowWithOptionalEntry()];
                array_walk($data['row'], function (int|string &$value) {
                    $value = (string) $value;
                });

                return $data;
            },
            'arrayShiftOnAProperty' => function () {
                $holder = new stdClass;
                $holder->row = ByReferenceArrayFunctionTest::rowWithOptionalEntry();
                array_shift($holder->row);

                return $holder;
            },
        ];

        $this->assertSame(
            [
                'arrayPopUnderAKey' => '{"type":"object","properties":{"row":{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"}}}},"required":["row"]}',
                'keyPreservingSortUnderAKey' => '{"type":"object","properties":{"row":{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"}},"required":["a"]}},"required":["row"]}',
                'arrayWalkUnderAKey' => '{"type":"object","properties":{"row":{"type":"object","properties":{"a":{"type":"string"},"b":{"type":"string"}},"required":["a"]}},"required":["row"]}',
                'arrayShiftOnAProperty' => '{"type":"object","properties":{"row":{"type":"object","properties":{"a":{"type":"integer"},"b":{"type":"integer"}}}},"required":["row"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    private function closureReturnSchema(Closure $closure): string
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $type = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return json_encode(SchemaJson::castPropertiesToObjects($type->toSchema($config)), JSON_THROW_ON_ERROR);
    }


    /**
     * @return list<int>
     */
    private static function ints(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return non-empty-list<int>
     */
    private static function nonEmptyInts(): array
    {
        return [1];
    }


    /**
     * @return array<string, int>
     */
    private static function scoresByName(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{a: int, b?: int}
     */
    private static function rowWithOptionalEntry(): array
    {
        return ['a' => 1];
    }
}
