<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use RuntimeException;

/**
 * @phpstan-type ErrorWithCounts array{error: string, ...<string, int>}
 */
final class OpenArrayShapeTest extends TestCase
{
    use LoadsConfig;

    private const string NULLABLE_INTEGER_LIST = '{"type":"array","items":{"type":["integer","null"]}}';
    private const string NULLABLE_INTEGER_MAP = '{"type":"object","additionalProperties":{"type":["integer","null"]}}';
    private const string NULLABLE_INTEGER_LIST_OR_MAP = '{"anyOf":[' . self::NULLABLE_INTEGER_LIST . ',' . self::NULLABLE_INTEGER_MAP . ']}';
    private const string ERROR_WITH_INTEGER_ENTRIES = '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}}';
    private const string ERROR_WITH_ANY_ENTRIES = '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":true}';
    private const string ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES = '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":["integer","boolean"]}}';
    private const string ID_AND_NAME_BEFORE_BOOLEANS = '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":"boolean"},"minItems":2}';
    private const string ID_AND_NAME_BEFORE_ANY_ENTRIES = '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2}';
    private const string USER = '{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":["string","null"]}},"required":["id","email"]}';
    private const string USER_WHOSE_EMAIL_MAY_BE_UNSET = '{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":["string","null"]}},"required":["id"]}';
    private const array NO_ENTRIES = [];

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


    #[Test]
    public function aListWithANarrowedFirstEntryStaysAPlainList(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(self::NULLABLE_INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function aListWithTwoNarrowedLeadingEntriesStaysAPlainList(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null && $scores[1] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(self::NULLABLE_INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function aListWithAFirstEntryNarrowedByATypeCheckStaysAPlainList(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::intsOrStrings();

            if (is_string($values[0])) {
                return $values;
            }

            return [];
        };

        $this->assertSame('{"type":"array","items":{"type":["string","integer"]}}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function declaredIntKeyedArraysWithANarrowedFirstEntryStayPlainLists(): void
    {
        $closures = [
            'withAnIntKeyType' => function () {
                $scores = OpenArrayShapeTest::intKeyedNullableInts();

                if ($scores[0] !== null) {
                    return $scores;
                }

                return [];
            },
            'withoutAKeyType' => function () {
                $scores = OpenArrayShapeTest::nullableIntItems();

                if ($scores[0] !== null) {
                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::NULLABLE_INTEGER_LIST),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aListOfUnknownValuesWithANarrowedFirstEntryAllowsAnyOtherEntry(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::mixedList();

            if (is_int($values[0])) {
                return $values;
            }

            return [];
        };

        $this->assertSame('{"type":"array","prefixItems":[{"type":"integer"}]}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function aListWithAFirstEntryKnownToExistStaysAPlainList(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if (array_key_exists(0, $scores)) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(self::NULLABLE_INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function aListOfShapesWithANarrowedFirstEntryStaysAPlainList(): void
    {
        $closure = function () {
            $users = OpenArrayShapeTest::users();

            if ($users[0]['email'] !== null) {
                return $users;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":["string","null"]}},"required":["id","email"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aListNestedInAShapeStaysAPlainListAfterItsFirstEntryIsNarrowed(): void
    {
        $closure = function () {
            $ranking = OpenArrayShapeTest::ranking();

            if ($ranking['scores'][0] !== null) {
                return $ranking;
            }

            return null;
        };

        $this->assertSame(
            '{"type":["object","null"],'
                . '"properties":{"scores":' . self::NULLABLE_INTEGER_LIST . ',"total":{"type":"integer"}},'
                . '"required":["scores","total"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aNonEmptyListWithANarrowedFirstEntryStaysNonEmpty(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nonEmptyNullableInts();

            if ($scores[0] !== null) {
                return $scores;
            }

            return null;
        };

        $this->assertSame(
            '{"type":["array","null"],"items":{"type":["integer","null"]},"minItems":1}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aListCheckedForItsFirstEntryStaysAPlainList(): void
    {
        $closures = [
            'nullableIntegers' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (isset($scores[0])) {
                    return $scores;
                }

                return null;
            },
            'integers' => function () {
                $scores = OpenArrayShapeTest::ints();

                if (isset($scores[0])) {
                    return $scores;
                }

                return null;
            },
            'shapes' => function () {
                $users = OpenArrayShapeTest::users();

                if (! isset($users[0])) {
                    return null;
                }

                return $users;
            },
            'unknownValues' => function () {
                $values = OpenArrayShapeTest::mixedList();

                if (isset($values[0])) {
                    return $values;
                }

                return null;
            },
            'returnedFromBothBranches' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (isset($scores[0])) {
                    return $scores;
                }

                return $scores;
            },
        ];

        $this->assertSame(
            [
                'nullableIntegers' => '{"type":["array","null"],"items":{"type":["integer","null"]}}',
                'integers' => '{"type":["array","null"],"items":{"type":"integer"}}',
                'shapes' => '{"type":["array","null"],"items":{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":["string","null"]}},"required":["id","email"]}}',
                'unknownValues' => '{"type":["array","null"],"items":{"type":"string","description":"mixed"}}',
                'returnedFromBothBranches' => self::NULLABLE_INTEGER_LIST,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            [
                'nullableIntegers' => 'Array<number|null>|null',
                'integers' => 'number[]|null',
                'shapes' => implode("\n", [
                    'Array<{',
                    '    id: number',
                    '    email: string|null',
                    '}>|null',
                ]),
                'unknownValues' => 'unknown[]|null',
                'returnedFromBothBranches' => 'Array<number|null>',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function anIndexCheckDoesNotSetTheMinimumLengthOfAList(): void
    {
        $closures = [
            'isset' => function () {
                $scores = OpenArrayShapeTest::ints();

                if (! isset($scores[0])) {
                    throw new RuntimeException;
                }

                return $scores;
            },
            'arrayKeyExists' => function () {
                $scores = OpenArrayShapeTest::ints();

                if (array_key_exists(0, $scores)) {
                    return $scores;
                }

                return [];
            },
            'twoIndexes' => function () {
                $scores = OpenArrayShapeTest::ints();

                if (! isset($scores[0], $scores[1])) {
                    throw new RuntimeException;
                }

                return $scores;
            },
            'nextToAnUncheckedList' => function () {
                $scores = OpenArrayShapeTest::ints();

                if (isset($scores[0])) {
                    return $scores;
                }

                return OpenArrayShapeTest::ints();
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"array","items":{"type":"integer"}}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            array_fill_keys(array_keys($closures), 'number[]'),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aDeclaredListWhoseLeadingEntryTheOtherEntriesAllowIsAPlainList(): void
    {
        $closure = fn () => OpenArrayShapeTest::idAndNullableInts();

        $this->assertSame('{"type":"array","items":{"type":["integer","null"]}}', $this->closureReturnSchema($closure));
        $this->assertSame('Array<number|null>', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function leadingEntriesTheOtherEntriesDoNotAllowAreListedOneByOne(): void
    {
        $closures = [
            'everyLeadingEntry' => fn () => OpenArrayShapeTest::idNameAndFlags(),
            'oneOfTwoLeadingEntries' => fn () => OpenArrayShapeTest::nameIdAndCounts(),
            'aKnownTypeBeforeUnknownValues' => fn () => OpenArrayShapeTest::idAndUnknownEntries(),
        ];

        $this->assertSame(
            [
                'everyLeadingEntry' => self::ID_AND_NAME_BEFORE_BOOLEANS,
                'oneOfTwoLeadingEntries' => '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"}],"items":{"type":"integer"},"minItems":2}',
                'aKnownTypeBeforeUnknownValues' => '{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            [
                'everyLeadingEntry' => '[number, string, ...boolean[]]',
                'oneOfTwoLeadingEntries' => '[string, number, ...number[]]',
                'aKnownTypeBeforeUnknownValues' => '[number, ...unknown[]]',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aTupleWithANarrowedEntryAllowsNoOtherEntries(): void
    {
        $closure = function () {
            $pair = OpenArrayShapeTest::nullableIntAndString();

            if ($pair[0] !== null) {
                return $pair;
            }

            return null;
        };

        $this->assertSame(
            '{"type":["array","null"],"prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aMapWithANarrowedKeyHasAnIndexSignatureInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    alice?: number',
                '    [key: string]: number|null|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aMapWithAKeyNarrowedByATypeCheckHasAnIndexSignatureOfEveryValueTypeInTypeScript(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::intsOrStringsByName();

            if (is_string($values['alice'])) {
                return $values;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    alice?: string',
                '    [key: string]: string|number|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aMapOfUnknownValuesWithANarrowedKeyHasAnUnknownIndexSignatureInTypeScript(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::mixedByName();

            if (is_int($values['alice'])) {
                return $values;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    alice?: number',
                '    [key: string]: unknown',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aMapWithTwoNarrowedKeysHasOneIndexSignatureInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableIntsByName();

            if ($scores['alice'] !== null && $scores['bob'] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    alice?: number',
                '    bob?: number',
                '    [key: string]: number|null|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aMapOfShapesWithANarrowedEntryHasAnIndexSignatureOfTheShapeInTypeScript(): void
    {
        $closure = function () {
            $users = OpenArrayShapeTest::usersByName();

            if ($users['admin']['email'] !== null) {
                return $users;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    admin?: {',
                '        id: number',
                '        email: string',
                '    }',
                '    [key: string]: {',
                '        id: number',
                '        email: string|null',
                '    }|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function anIntKeyedMapWithANarrowedKeyHasAnIndexSignatureInTypeScript(): void
    {
        $closure = function (?int $first, ?int $second) {
            $scores = array_map(fn (?int $score) => $score, [1 => $first, 2 => $second]);

            if ($scores[1] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                '{',
                "    '1'?: number",
                '    [key: string]: number|null|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aMapNestedInAShapeHasAnIndexSignatureInTypeScript(): void
    {
        $closure = function () {
            $report = OpenArrayShapeTest::report();

            if ($report['scores']['alice'] !== null) {
                return $report;
            }

            return null;
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    scores: {',
                '        alice?: number',
                '        [key: string]: number|null|undefined',
                '    }',
                '    total: number',
                '}|null',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aShapeWithANarrowedKeyHasNoIndexSignatureInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::closedScores();

            if ($scores['alice'] !== null) {
                return $scores;
            }

            return null;
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    alice: number',
                '    bob: number',
                '}|null',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aListWithANarrowedFirstEntryStaysAListInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame('Array<number|null>', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function aListWithTwoNarrowedLeadingEntriesStaysAListInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null && $scores[1] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame('Array<number|null>', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function aListOfUnknownValuesWithANarrowedFirstEntryHasAnUnknownRestElementInTypeScript(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::mixedList();

            if (is_int($values[0])) {
                return $values;
            }

            return [];
        };

        $this->assertSame('[number?, ...unknown[]]', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function aListOfShapesWithANarrowedFirstEntryStaysAListOfTheShapeInTypeScript(): void
    {
        $closure = function () {
            $users = OpenArrayShapeTest::users();

            if ($users[0]['email'] !== null) {
                return $users;
            }

            return [];
        };

        $this->assertSame(
            implode("\n", [
                'Array<{',
                '    id: number',
                '    email: string|null',
                '}>',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aListNestedInAShapeStaysAListInTypeScript(): void
    {
        $closure = function () {
            $ranking = OpenArrayShapeTest::ranking();

            if ($ranking['scores'][0] !== null) {
                return $ranking;
            }

            return null;
        };

        $this->assertSame(
            implode("\n", [
                '{',
                '    scores: Array<number|null>',
                '    total: number',
                '}|null',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aTupleWithANarrowedEntryHasNoRestElementInTypeScript(): void
    {
        $closure = function () {
            $pair = OpenArrayShapeTest::nullableIntAndString();

            if ($pair[0] !== null) {
                return $pair;
            }

            return null;
        };

        $this->assertSame('[number, string]|null', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function aListWithANarrowedEntryAfterAnUnknownOneStaysAPlainList(): void
    {
        $closures = [
            'secondEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[1] !== null) {
                    return $scores;
                }

                return [];
            },
            'sixthEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[5] !== null) {
                    return $scores;
                }

                return [];
            },
            'firstAndThirdEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[0] !== null && $scores[2] !== null) {
                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::NULLABLE_INTEGER_LIST),
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            array_fill_keys(array_keys($closures), 'Array<number|null>'),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aListThatMayHaveGapsWithANarrowedFirstEntryMayBeAListOrAMap(): void
    {
        $closure = function () {
            $scores = array_filter(OpenArrayShapeTest::nullableInts(), fn (?int $score) => $score !== 3);

            if ($scores[0] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"anyOf":['
                . self::NULLABLE_INTEGER_LIST . ','
                . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                . ']}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                'Array<number|null>|{',
                "    '0'?: number",
                '    [key: string]: number|null|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aListThatMayHaveGapsWithANarrowedLaterEntryMayBeAListOrAMap(): void
    {
        $closure = function () {
            $scores = array_filter(OpenArrayShapeTest::nullableInts(), fn (?int $score) => $score !== 3);

            if ($scores[5] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"anyOf":['
                . self::NULLABLE_INTEGER_LIST . ','
                . '{"type":"object","properties":{"5":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                . ']}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                'Array<number|null>|{',
                "    '5'?: number",
                '    [key: string]: number|null|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aMapWhoseKeysMayBeStringsStaysAnObjectAfterItsFirstIntKeyIsNarrowed(): void
    {
        $closures = [
            'intOrStringKeys' => function () {
                $scores = OpenArrayShapeTest::arrayKeyedNullableInts();

                if ($scores[0] !== null) {
                    return $scores;
                }

                return [];
            },
            'stringKeys' => function () {
                $scores = OpenArrayShapeTest::nullableIntsByName();

                // @phpstan-ignore offsetAccess.notFound
                if ($scores[0] !== null) {
                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($closures),
                '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}',
            ),
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            array_fill_keys(array_keys($closures), implode("\n", [
                '{',
                "    '0'?: number",
                '    [key: string]: number|null|undefined',
                '}',
            ])),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aMapWhoseKeysMayBeIntsStaysAnObjectAfterAStringKeyIsNarrowed(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::arrayKeyedNullableInts();

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
    public function aKeyPreservingSortOfAListWithANarrowedEntryMayLeaveAMap(): void
    {
        $closures = [
            'firstEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[0] !== null) {
                    asort($scores);

                    return $scores;
                }

                return [];
            },
            'sixthEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[5] !== null) {
                    asort($scores);

                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($closures),
                '{"anyOf":[' . self::NULLABLE_INTEGER_LIST . ',{"type":"object","additionalProperties":{"type":["integer","null"]}}]}',
            ),
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            array_fill_keys(array_keys($closures), 'Array<number|null>|Record<string, number|null>'),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function writingUnderAStringKeyMakesAListWithANarrowedEntryAMap(): void
    {
        $closure = function (string $name) {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[5] !== null) {
                $scores[$name] = 5;

                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"object","additionalProperties":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame('Record<string, number|null>', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function aListNestedInAShapeStaysAPlainListAfterALaterEntryIsNarrowed(): void
    {
        $closure = function () {
            $ranking = OpenArrayShapeTest::ranking();

            if ($ranking['scores'][5] !== null) {
                return $ranking;
            }

            return null;
        };

        $this->assertSame(
            '{"type":["object","null"],"properties":{"scores":' . self::NULLABLE_INTEGER_LIST . ',"total":{"type":"integer"}},"required":["scores","total"]}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                '{',
                '    scores: Array<number|null>',
                '    total: number',
                '}|null',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function unsettingTheNarrowedEntryOfAListMayLeaveAGap(): void
    {
        $closures = [
            'firstEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[0] !== null) {
                    unset($scores[0]);

                    return $scores;
                }

                return [];
            },
            'sixthEntry' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[5] !== null) {
                    unset($scores[5]);

                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::NULLABLE_INTEGER_LIST_OR_MAP),
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            array_fill_keys(array_keys($closures), 'Array<number|null>|Record<string, number|null>'),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function unsettingAnotherEntryOfAListWithANarrowedFirstEntryMayLeaveAGap(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null) {
                unset($scores[3]);

                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"anyOf":['
                . self::NULLABLE_INTEGER_LIST . ','
                . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                . ']}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                'Array<number|null>|{',
                "    '0'?: number",
                '    [key: string]: number|null|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function unsettingAnEntryOfANonEmptyListWithANarrowedEntryMayLeaveItEmpty(): void
    {
        $closures = [
            'theNarrowedEntry' => function () {
                $scores = OpenArrayShapeTest::nonEmptyNullableInts();

                if ($scores[0] === null) {
                    throw new RuntimeException;
                }

                unset($scores[0]);

                return $scores;
            },
            'anotherEntry' => function () {
                $scores = OpenArrayShapeTest::nonEmptyNullableInts();

                if ($scores[0] === null) {
                    throw new RuntimeException;
                }

                unset($scores[3]);

                return $scores;
            },
            'oneOfTwoNarrowedEntries' => function () {
                $scores = OpenArrayShapeTest::nonEmptyNullableInts();

                if ($scores[0] === null || $scores[1] === null) {
                    throw new RuntimeException;
                }

                unset($scores[0]);

                return $scores;
            },
        ];

        $this->assertSame(
            [
                'theNarrowedEntry' => self::NULLABLE_INTEGER_LIST_OR_MAP,
                'anotherEntry' => '{"anyOf":['
                    . self::NULLABLE_INTEGER_LIST . ','
                    . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                    . ']}',
                'oneOfTwoNarrowedEntries' => '{"anyOf":['
                    . self::NULLABLE_INTEGER_LIST . ','
                    . '{"type":"object","properties":{"1":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                    . ']}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function unsettingAnEntryThatMayStayKeepsItKnown(): void
    {
        $closures = [
            'inABranch' => function (bool $flag) {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[0] !== null) {
                    if ($flag) {
                        unset($scores[0]);
                    }

                    return $scores;
                }

                return [];
            },
            'underADynamicKey' => function (int $index) {
                $scores = OpenArrayShapeTest::nullableInts();

                if ($scores[0] !== null) {
                    unset($scores[$index]);

                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($closures),
                '{"anyOf":['
                    . self::NULLABLE_INTEGER_LIST . ','
                    . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                    . ']}',
            ),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function unsettingAKeyOfAMapWithANarrowedKeyKeepsItAnObject(): void
    {
        $closures = [
            'theNarrowedKey' => function () {
                $scores = OpenArrayShapeTest::nullableIntsByName();

                if ($scores['alice'] !== null) {
                    unset($scores['alice']);

                    return $scores;
                }

                return [];
            },
            'anotherKey' => function () {
                $scores = OpenArrayShapeTest::nullableIntsByName();

                if ($scores['alice'] !== null) {
                    unset($scores['bob']);

                    return $scores;
                }

                return [];
            },
            'oneOfTwoNarrowedKeys' => function () {
                $scores = OpenArrayShapeTest::nullableIntsByName();

                if ($scores['alice'] !== null && $scores['bob'] !== null) {
                    unset($scores['alice']);

                    return $scores;
                }

                return [];
            },
        ];

        $this->assertSame(
            [
                'theNarrowedKey' => self::NULLABLE_INTEGER_MAP,
                'anotherKey' => '{"type":"object","properties":{"alice":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}',
                'oneOfTwoNarrowedKeys' => '{"type":"object","properties":{"bob":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anUnsealedShapeIsReadWhereverItIsDeclared(): void
    {
        $closures = [
            'returnTag' => fn () => OpenArrayShapeTest::errorWithCounts(),
            'varTag' => function () {
                /** @var array{error: string, ...<string, int>} */
                $payload = json_decode('{"error":"failed"}', true);

                return $payload;
            },
            'paramTag' => fn () => OpenArrayShapeTest::passThrough(['error' => 'failed']),
            'typeAlias' => fn () => OpenArrayShapeTest::errorWithCountsFromAlias(),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::ERROR_WITH_INTEGER_ENTRIES),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anUnsealedShapeSpanningLinesKeepsItsOptionalKeyAndOtherEntries(): void
    {
        $closure = fn () => OpenArrayShapeTest::errorDetailsAndCounts();

        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"},"details":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    details?: string',
                '    [key: string]: string|number|undefined',
                '}',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aListOfUnsealedShapesKeepsTheOtherEntriesOfEachItem(): void
    {
        $closure = fn () => OpenArrayShapeTest::rowsWithLabels();

        $this->assertSame(
            '{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"],"additionalProperties":{"type":"string"}}}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                'Array<{',
                '    id: number',
                '    [key: string]: number|string',
                '}>',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function unsealedShapesHaveAnIndexSignatureOrARestElementInTypeScript(): void
    {
        $closures = [
            'unknownEntries' => fn () => OpenArrayShapeTest::errorWithUnknownEntries(),
            'typedEntries' => fn () => OpenArrayShapeTest::errorWithCounts(),
            'openList' => fn () => OpenArrayShapeTest::idNameAndFlags(),
            'openListOfUnknown' => fn () => OpenArrayShapeTest::idAndUnknownEntries(),
            'positionalWithAnyKeys' => fn () => OpenArrayShapeTest::idNameAndFlagsUnderAnyKey(),
        ];

        $this->assertSame(
            [
                'unknownEntries' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: unknown',
                    '}',
                ]),
                'typedEntries' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: string|number',
                    '}',
                ]),
                'openList' => '[number, string, ...boolean[]]',
                'openListOfUnknown' => '[number, ...unknown[]]',
                'positionalWithAnyKeys' => implode("\n", [
                    '{',
                    "    '0': number",
                    "    '1': string",
                    '    [key: string]: number|string|boolean',
                    '}',
                ]),
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function readingAnUnsealedShapeSeesItsOtherEntries(): void
    {
        $closures = [
            'aKeyOutsideTheShape' => function () {
                // @phpstan-ignore offsetAccess.notFound
                return OpenArrayShapeTest::errorWithCounts()['count'];
            },
            'aKnownKey' => fn () => OpenArrayShapeTest::errorWithCounts()['error'],
            'everyValue' => fn () => array_values(OpenArrayShapeTest::errorWithCounts()),
            'everyKey' => fn () => array_keys(OpenArrayShapeTest::errorWithCounts()),
            'eachValue' => function () {
                $values = [];

                foreach (OpenArrayShapeTest::errorWithCounts() as $value) {
                    $values[] = $value;
                }

                return $values;
            },
            'withoutItsKnownKey' => function () {
                $payload = OpenArrayShapeTest::errorWithCounts();
                unset($payload['error']);

                return $payload;
            },
        ];

        $this->assertSame(
            [
                'aKeyOutsideTheShape' => '{"type":"integer"}',
                'aKnownKey' => '{"type":"string"}',
                'everyValue' => '{"type":"array","items":{"type":["string","integer"]}}',
                'everyKey' => '{"type":"array","items":{"type":"string"}}',
                'eachValue' => '{"type":"array","items":{"type":["string","integer"]}}',
                'withoutItsKnownKey' => '{"type":"object","additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function reversingAnArrayWithEntriesOutsideItsShapeLeavesNoEntryAtAKnownIndex(): void
    {
        $closures = [
            'declaredList' => fn () => array_reverse(OpenArrayShapeTest::idNameAndFlags()),
            'firstEntryOfANarrowedList' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (! isset($scores[0])) {
                    throw new RuntimeException;
                }

                return array_reverse($scores)[0];
            },
            'intKeyNextToAStringKey' => fn () => array_reverse(OpenArrayShapeTest::idErrorAndIntKeyedFlags()),
            'intKeysNextToEntriesUnderAnyKey' => fn () => array_reverse(OpenArrayShapeTest::idNameAndFlagsUnderAnyKey()),
        ];

        $this->assertSame(
            [
                'declaredList' => '{"type":"array","items":{"type":["integer","string","boolean"]}}',
                'firstEntryOfANarrowedList' => '{"type":["integer","null"]}',
                'intKeyNextToAStringKey' => '{"type":"object","additionalProperties":{"type":["integer","string","boolean"]}}',
                'intKeysNextToEntriesUnderAnyKey' => '{"type":"object","additionalProperties":{"type":["integer","string","boolean"]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            [
                'declaredList' => 'Array<number|string|boolean>',
                'firstEntryOfANarrowedList' => 'number|null',
                'intKeyNextToAStringKey' => 'Record<string, number|string|boolean>',
                'intKeysNextToEntriesUnderAnyKey' => 'Record<string, number|string|boolean>',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function reversingAListWithEntriesOutsideItsShapeAndKeepingItsKeysMayLeaveAMap(): void
    {
        $closures = [
            'declaredList' => fn () => array_reverse(OpenArrayShapeTest::idNameAndFlags(), true),
            'declaredListWithAnUnknownFlag' => fn (bool $preservesKeys) => array_reverse(OpenArrayShapeTest::idNameAndFlags(), $preservesKeys),
            'narrowedList' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (! isset($scores[0])) {
                    throw new RuntimeException;
                }

                return array_reverse($scores, true);
            },
        ];

        $listOrMapOfEveryType = '{"anyOf":['
            . '{"type":"array","items":{"type":["integer","string","boolean"]}},'
            . '{"type":"object","additionalProperties":{"type":["integer","string","boolean"]}}'
            . ']}';

        $this->assertSame(
            [
                'declaredList' => $listOrMapOfEveryType,
                'declaredListWithAnUnknownFlag' => $listOrMapOfEveryType,
                'narrowedList' => self::NULLABLE_INTEGER_LIST_OR_MAP,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            [
                'declaredList' => 'Array<number|string|boolean>|Record<string, number|string|boolean>',
                'declaredListWithAnUnknownFlag' => 'Array<number|string|boolean>|Record<string, number|string|boolean>',
                'narrowedList' => 'Array<number|null>|Record<string, number|null>',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function reversingAShapeWithOnlyStringKeysKeepsItsKnownKeys(): void
    {
        $closures = [
            'stringKeyedEntriesOutsideTheShape' => fn () => array_reverse(OpenArrayShapeTest::errorAndCodeWithFlags()),
            'keepingTheKeys' => fn () => array_reverse(OpenArrayShapeTest::errorAndCodeWithFlags(), true),
            'intKeyedEntriesOutsideTheShape' => fn () => array_reverse(OpenArrayShapeTest::errorWithIntKeyedFlags()),
        ];

        $codeThenError = '{"type":"object","properties":{"code":{"type":"integer"},"error":{"type":"string"}},"required":["code","error"],"additionalProperties":{"type":"boolean"}}';

        $this->assertSame(
            [
                'stringKeyedEntriesOutsideTheShape' => $codeThenError,
                'keepingTheKeys' => $codeThenError,
                'intKeyedEntriesOutsideTheShape' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"boolean"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aKeyAComparedArrayMayHoldOutsideItsShapeMayBeKeptOrRemoved(): void
    {
        $closures = [
            'intersectKey' => fn (string $error, int $count) => array_intersect_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::errorWithCounts(),
            ),
            'diffKey' => fn (string $error, int $count) => array_diff_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::errorWithCounts(),
            ),
            'intersectKeyWithAList' => fn (string $error, int $count) => array_intersect_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::idNameAndFlags(),
            ),
            'diffKeyWithAList' => fn (string $error, int $count) => array_diff_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::idNameAndFlags(),
            ),
            'diffKeyWithAClosedShapeToo' => fn (string $error, int $count) => array_diff_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::errorOnly(),
                OpenArrayShapeTest::errorWithCounts(),
            ),
        ];

        $errorAndMaybeCount = '{"type":"object","properties":{"error":{"type":"string"},"count":{"type":"integer"}},"required":["error"]}';
        $maybeErrorAndMaybeCount = '{"type":"object","properties":{"error":{"type":"string"},"count":{"type":"integer"}}}';
        $maybeCount = '{"type":"object","properties":{"count":{"type":"integer"}}}';

        $this->assertSame(
            [
                'intersectKey' => $errorAndMaybeCount,
                'diffKey' => $maybeCount,
                'intersectKeyWithAList' => $maybeErrorAndMaybeCount,
                'diffKeyWithAList' => $maybeErrorAndMaybeCount,
                'diffKeyWithAClosedShapeToo' => $maybeCount,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aKeyAClosedComparedShapeDoesNotListIsNeverPresent(): void
    {
        $closures = [
            'intersectKey' => fn (string $error, int $count) => array_intersect_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::errorOnly(),
                OpenArrayShapeTest::errorWithCounts(),
            ),
            'diffKey' => fn (string $error, int $count) => array_diff_key(
                ['error' => $error, 'count' => $count],
                OpenArrayShapeTest::errorOnly(),
            ),
        ];

        $this->assertSame(
            [
                'intersectKey' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"]}',
                'diffKey' => '{"type":"object","properties":{"count":{"type":"integer"}},"required":["count"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function comparingTheKeysOfAnArrayWithEntriesOutsideItsShapeKeepsThoseEntries(): void
    {
        $closures = [
            'intersectKey' => fn () => array_intersect_key(OpenArrayShapeTest::errorWithCounts(), ['error' => true]),
            'intersectKeyWithEntriesOutsideBothShapes' => fn () => array_intersect_key(
                OpenArrayShapeTest::errorWithCounts(),
                OpenArrayShapeTest::errorAndCodeWithFlags(),
            ),
            'diffKey' => fn () => array_diff_key(OpenArrayShapeTest::errorWithCounts(), ['error' => true]),
        ];

        $this->assertSame(
            [
                'intersectKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'intersectKeyWithEntriesOutsideBothShapes' => self::ERROR_WITH_INTEGER_ENTRIES,
                'diffKey' => '{"type":"object","additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function removingEntriesOfAListWithAKnownFirstEntryReachesItsOtherEntries(): void
    {
        $closures = [
            'arrayUnique' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (! isset($scores[0])) {
                    throw new RuntimeException;
                }

                return array_unique($scores);
            },
            'arrayFilter' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (! isset($scores[0])) {
                    throw new RuntimeException;
                }

                return array_filter($scores);
            },
            'arrayFilterWithACallback' => function () {
                $scores = OpenArrayShapeTest::nullableInts();

                if (! isset($scores[0])) {
                    throw new RuntimeException;
                }

                return array_filter($scores, fn (?int $score) => $score !== null);
            },
            'arrayUniqueOfADeclaredList' => fn () => array_unique(OpenArrayShapeTest::idAndFlags()),
        ];

        $integerListOrMapWithAnOptionalFirstEntry = '{"anyOf":['
            . '{"type":"array","items":{"type":"integer"}},'
            . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":"integer"}}'
            . ']}';

        $this->assertSame(
            [
                'arrayUnique' => '{"anyOf":['
                    . self::NULLABLE_INTEGER_LIST . ','
                    . '{"type":"object","properties":{"0":{"type":"integer"}},"required":["0"],"additionalProperties":{"type":["integer","null"]}}'
                    . ']}',
                'arrayFilter' => $integerListOrMapWithAnOptionalFirstEntry,
                'arrayFilterWithACallback' => $integerListOrMapWithAnOptionalFirstEntry,
                'arrayUniqueOfADeclaredList' => '{"anyOf":['
                    . '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"},"minItems":1},'
                    . '{"type":"object","properties":{"0":{"type":"integer"}},"required":["0"],"additionalProperties":{"type":"boolean"}}'
                    . ']}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function filteringAMapWithAKnownKeyNarrowsItsOtherEntries(): void
    {
        $closures = [
            'withoutACallback' => fn () => array_filter(OpenArrayShapeTest::errorWithNullableCounts()),
            'withATypeCheckFunction' => fn () => array_filter(OpenArrayShapeTest::errorWithNullableCounts(), 'is_int'),
            'withAClosure' => fn () => array_filter(
                OpenArrayShapeTest::errorWithNullableCounts(),
                fn (string|int|null $value) => $value !== null,
            ),
            'byItsKeys' => fn () => array_filter(
                OpenArrayShapeTest::errorWithNullableCounts(),
                fn (string $key) => $key !== 'error',
                ARRAY_FILTER_USE_KEY,
            ),
        ];

        $maybeErrorWithIntegers = '{"type":"object","properties":{"error":{"type":"string"}},"additionalProperties":{"type":"integer"}}';

        $this->assertSame(
            [
                'withoutACallback' => $maybeErrorWithIntegers,
                'withATypeCheckFunction' => '{"type":"object","additionalProperties":{"type":"integer"}}',
                'withAClosure' => $maybeErrorWithIntegers,
                'byItsKeys' => self::NULLABLE_INTEGER_MAP,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function removingEntriesOfAClosedShapeOrAPairWorksAsBefore(): void
    {
        $closures = [
            'arrayUniqueOfATuple' => fn () => array_unique(OpenArrayShapeTest::idAndName()),
            'arrayUniqueOfAList' => fn () => array_unique(OpenArrayShapeTest::nullableInts()),
            'arrayFilterOfAShape' => fn () => array_filter(OpenArrayShapeTest::closedScores()),
            'arrayFilterOfAList' => fn () => array_filter(OpenArrayShapeTest::nullableInts()),
            'arrayFilterOfAListWithACallback' => fn () => array_filter(
                OpenArrayShapeTest::nullableInts(),
                fn (?int $score) => $score !== null,
            ),
            'arrayDiffOfADeclaredList' => fn () => array_diff(OpenArrayShapeTest::idAndFlags(), [1]),
        ];

        $integerListOrMap = '{"anyOf":[{"type":"array","items":{"type":"integer"}},{"type":"object","additionalProperties":{"type":"integer"}}]}';

        $this->assertSame(
            [
                'arrayUniqueOfATuple' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":1,"maxItems":2}',
                'arrayUniqueOfAList' => self::NULLABLE_INTEGER_LIST_OR_MAP,
                'arrayFilterOfAShape' => '{"type":"object","properties":{"alice":{"type":"integer"},"bob":{"type":"integer"}}}',
                'arrayFilterOfAList' => $integerListOrMap,
                'arrayFilterOfAListWithACallback' => $integerListOrMap,
                'arrayDiffOfADeclaredList' => '{"anyOf":['
                    . '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"}},'
                    . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":"boolean"}}'
                    . ']}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aNestedUnsetReachesTheEntriesOutsideTheShapeOfAList(): void
    {
        $closures = [
            'underADynamicKey' => function (int $index) {
                $users = OpenArrayShapeTest::users();

                if (! isset($users[0])) {
                    throw new RuntimeException;
                }

                unset($users[$index]['email']);

                return $users;
            },
            'underAKeyOutsideTheShape' => function () {
                $users = OpenArrayShapeTest::users();

                if (! isset($users[0])) {
                    throw new RuntimeException;
                }

                unset($users[1]['email']);

                return $users;
            },
            'withoutAKnownEntry' => function (int $index) {
                $users = OpenArrayShapeTest::users();
                unset($users[$index]['email']);

                return $users;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"array","items":' . self::USER_WHOSE_EMAIL_MAY_BE_UNSET . '}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aNestedUnsetReachesTheEntriesOutsideTheShapeOfAMap(): void
    {
        $closures = [
            'underADynamicKey' => function (string $name) {
                $users = OpenArrayShapeTest::usersByName();

                if (! isset($users['alice'])) {
                    throw new RuntimeException;
                }

                unset($users[$name]['email']);

                return $users;
            },
            'underAKeyOutsideTheShape' => function () {
                $users = OpenArrayShapeTest::usersByName();

                if (! isset($users['alice'])) {
                    throw new RuntimeException;
                }

                unset($users['bob']['email']);

                return $users;
            },
            'readingTheEntryAfterwards' => function () {
                $users = OpenArrayShapeTest::usersByName();

                if (! isset($users['alice'])) {
                    throw new RuntimeException;
                }

                unset($users['bob']['email']);

                return $users['bob'];
            },
            'ofADeclaredShape' => function (string $name) {
                $users = OpenArrayShapeTest::adminAndUsersByName();
                unset($users[$name]['email']);

                return $users;
            },
        ];

        $this->assertSame(
            [
                'underADynamicKey' => '{"type":"object",'
                    . '"properties":{"alice":' . self::USER_WHOSE_EMAIL_MAY_BE_UNSET . '},"required":["alice"],'
                    . '"additionalProperties":' . self::USER_WHOSE_EMAIL_MAY_BE_UNSET . '}',
                'underAKeyOutsideTheShape' => '{"type":"object",'
                    . '"properties":{"alice":' . self::USER . '},"required":["alice"],'
                    . '"additionalProperties":' . self::USER_WHOSE_EMAIL_MAY_BE_UNSET . '}',
                'readingTheEntryAfterwards' => self::USER_WHOSE_EMAIL_MAY_BE_UNSET,
                'ofADeclaredShape' => '{"type":"object",'
                    . '"properties":{"admin":' . self::USER_WHOSE_EMAIL_MAY_BE_UNSET . '},"required":["admin"],'
                    . '"additionalProperties":' . self::USER_WHOSE_EMAIL_MAY_BE_UNSET . '}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aNestedUnsetUnderAKnownKeyChangesOnlyThatEntry(): void
    {
        $closure = function () {
            $users = OpenArrayShapeTest::usersByName();

            if (! isset($users['alice'])) {
                throw new RuntimeException;
            }

            unset($users['alice']['email']);

            return $users;
        };

        $this->assertSame(
            '{"type":"object",'
                . '"properties":{"alice":{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"]}},"required":["alice"],'
                . '"additionalProperties":' . self::USER . '}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aUnionOfShapesDocumentsTheOtherEntriesOfEveryMember(): void
    {
        $closures = [
            'ternary' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithFlags(),
            'reversedTernary' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithFlags() : OpenArrayShapeTest::errorWithCounts(),
            'earlyReturn' => function (bool $flag) {
                if ($flag) {
                    return OpenArrayShapeTest::errorWithCounts();
                }

                return OpenArrayShapeTest::errorWithFlags();
            },
            'reassignment' => function (bool $flag) {
                $payload = OpenArrayShapeTest::errorWithCounts();

                if ($flag) {
                    $payload = OpenArrayShapeTest::errorWithFlags();
                }

                return $payload;
            },
            'declaredUnion' => function () {
                /** @var array{error: string, ...<string, int>}|array{error: string, ...<string, bool>} */
                $payload = json_decode('{"error":"failed"}', true);

                return $payload;
            },
            'threeMembers' => fn (int $kind) => match ($kind) {
                1 => OpenArrayShapeTest::errorOnly(),
                2 => OpenArrayShapeTest::errorWithCounts(),
                default => OpenArrayShapeTest::errorWithFlags(),
            },
            'nullable' => fn (bool $flag, bool $other) => $flag
                ? OpenArrayShapeTest::errorWithCounts()
                : ($other ? OpenArrayShapeTest::errorWithFlags() : null),
        ];

        $this->assertSame(
            [
                'ternary' => self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES,
                'reversedTernary' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":["boolean","integer"]}}',
                'earlyReturn' => self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES,
                'reassignment' => self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES,
                'declaredUnion' => self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES,
                'threeMembers' => self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES,
                'nullable' => '{"type":["object","null"],"properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":["integer","boolean"]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aShapeWithoutOtherEntriesAddsNoneToAUnion(): void
    {
        $closures = [
            'closedFirst' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorOnly() : OpenArrayShapeTest::errorWithCounts(),
            'closedSecond' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorOnly(),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::ERROR_WITH_INTEGER_ENTRIES),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function otherEntriesOfAnyTypeStayOfAnyTypeInAUnion(): void
    {
        $closures = [
            'afterIntegers' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithUnknownEntries(),
            'beforeIntegers' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithUnknownEntries() : OpenArrayShapeTest::errorWithCounts(),
            'afterStrings' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithNames() : OpenArrayShapeTest::errorWithUnknownEntries(),
            'beforeStrings' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithUnknownEntries() : OpenArrayShapeTest::errorWithNames(),
            'afterAClosedShape' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorOnly() : OpenArrayShapeTest::errorWithUnknownEntries(),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::ERROR_WITH_ANY_ENTRIES),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aUnionOfListsDocumentsTheOtherEntriesOfEveryMember(): void
    {
        $closures = [
            'booleansThenStrings' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndFlags() : OpenArrayShapeTest::idNameAndLabels(),
            'stringsThenBooleans' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndLabels() : OpenArrayShapeTest::idNameAndFlags(),
            'closedTupleFirst' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idAndName() : OpenArrayShapeTest::idNameAndFlags(),
            'closedTupleSecond' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndFlags() : OpenArrayShapeTest::idAndName(),
            'anyTypeFirst' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndUnknownEntries() : OpenArrayShapeTest::idNameAndFlags(),
            'anyTypeSecond' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndFlags() : OpenArrayShapeTest::idNameAndUnknownEntries(),
        ];

        $this->assertSame(
            [
                'booleansThenStrings' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":["boolean","string"]},"minItems":2}',
                'stringsThenBooleans' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":["string","boolean"]},"minItems":2}',
                'closedTupleFirst' => self::ID_AND_NAME_BEFORE_BOOLEANS,
                'closedTupleSecond' => self::ID_AND_NAME_BEFORE_BOOLEANS,
                'anyTypeFirst' => self::ID_AND_NAME_BEFORE_ANY_ENTRIES,
                'anyTypeSecond' => self::ID_AND_NAME_BEFORE_ANY_ENTRIES,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aUnionOfShapesHasOneIndexSignatureOrRestElementInTypeScript(): void
    {
        $closures = [
            'typedEntries' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithFlags(),
            'unknownEntries' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithUnknownEntries(),
            'openLists' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndFlags() : OpenArrayShapeTest::idNameAndLabels(),
            'openListOfUnknown' => fn (bool $flag) => $flag ? OpenArrayShapeTest::idNameAndFlags() : OpenArrayShapeTest::idNameAndUnknownEntries(),
        ];

        $this->assertSame(
            [
                'typedEntries' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: string|number|boolean',
                    '}',
                ]),
                'unknownEntries' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: unknown',
                    '}',
                ]),
                'openLists' => '[number, string, ...Array<boolean|string>]',
                'openListOfUnknown' => '[number, string, ...unknown[]]',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function readingAUnionOfShapesSeesTheOtherEntriesOfEveryMember(): void
    {
        $closures = [
            'aKeyOutsideTheShape' => function (bool $flag) {
                $payload = $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithFlags();

                // @phpstan-ignore offsetAccess.notFound
                return $payload['count'];
            },
            'everyValue' => function (bool $flag) {
                $payload = $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithFlags();

                return array_values($payload);
            },
            'everyKey' => function (bool $flag) {
                $payload = $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithIntKeyedFlags();

                return array_keys($payload);
            },
        ];

        $this->assertSame(
            [
                'aKeyOutsideTheShape' => '{"type":["integer","boolean"]}',
                'everyValue' => '{"type":"array","items":{"type":["string","integer","boolean"]}}',
                'everyKey' => '{"type":"array","items":{"type":["string","integer"]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function otherEntriesOfAUnionOfShapesMergeTheWayAKnownEntryDoes(): void
    {
        $closures = [
            'shapesWithTheSameKey' => fn (bool $flag) => $flag
                ? OpenArrayShapeTest::errorWithRowsOfIntegerIds()
                : OpenArrayShapeTest::errorWithRowsOfStringIds(),
            'shapesInsideAKnownEntry' => fn (bool $flag) => $flag
                ? ['data' => OpenArrayShapeTest::errorWithCounts()]
                : ['data' => OpenArrayShapeTest::errorWithFlags()],
            'equalTypes' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithCountsFromAlias(),
        ];

        $this->assertSame(
            [
                'shapesWithTheSameKey' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"object","properties":{"id":{"type":["integer","string"]}},"required":["id"]}}',
                'shapesInsideAKnownEntry' => '{"type":"object","properties":{"data":' . self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES . '},"required":["data"]}',
                'equalTypes' => self::ERROR_WITH_INTEGER_ENTRIES,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aUnionOfNarrowedMapsOrListsDocumentsTheOtherEntriesOfEveryMember(): void
    {
        $closures = [
            'maps' => function (bool $flag) {
                $scores = OpenArrayShapeTest::nullableIntsByName();
                $labels = OpenArrayShapeTest::intsOrStringsByName();

                if (isset($scores['total'], $labels['total'])) {
                    return $flag ? $scores : $labels;
                }

                return null;
            },
            'lists' => function (bool $flag) {
                $scores = OpenArrayShapeTest::nullableInts();
                $labels = OpenArrayShapeTest::intsOrStrings();

                if (isset($scores[0], $labels[0])) {
                    return $flag ? $scores : $labels;
                }

                return null;
            },
        ];

        $this->assertSame(
            [
                'maps' => '{"type":["object","null"],"properties":{"total":{"type":["integer","string"]}},"required":["total"],"additionalProperties":{"type":["integer","string","null"]}}',
                'lists' => '{"type":["array","null"],"items":{"type":["integer","string","null"]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function shapesWithDifferentKeysUniteTheirOtherEntriesOnlyWhenTheShapesAreMerged(): void
    {
        $closures = [
            'extraKeyInTheSecondMember' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithNames() : OpenArrayShapeTest::errorAndCodeWithFlags(),
            'extraKeyInTheFirstMember' => fn (bool $flag) => $flag ? OpenArrayShapeTest::errorAndCodeWithFlags() : OpenArrayShapeTest::errorWithNames(),
        ];

        $errorWithStrings = '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"string"}}';
        $errorAndCodeWithBooleans = '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error","code"],"additionalProperties":{"type":"boolean"}}';

        $this->assertSame(
            [
                'extraKeyInTheSecondMember' => '{"anyOf":[' . $errorWithStrings . ',' . $errorAndCodeWithBooleans . ']}',
                'extraKeyInTheFirstMember' => '{"anyOf":[' . $errorAndCodeWithBooleans . ',' . $errorWithStrings . ']}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );

        $config = self::loadConfig();
        $config->data['arrays']['merge_shapes_in_type_unions'] = true;

        $this->assertSame(
            [
                'extraKeyInTheSecondMember' => '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error"],"additionalProperties":{"type":["string","boolean"]}}',
                'extraKeyInTheFirstMember' => '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error"],"additionalProperties":{"type":["boolean","string"]}}',
            ],
            array_map(fn (Closure $closure) => $this->closureReturnSchema($closure, $config), $closures),
        );
    }


    #[Test]
    public function anIntersectionOfShapesKeepsTheOtherEntriesEveryMemberAllows(): void
    {
        $closures = [
            'shapes' => function () {
                /** @var array{error: string|int, ...<string, int|string>}&array{error: string, ...<string, int|bool>} */
                $payload = json_decode('{"error":"failed"}', true);

                return $payload;
            },
            'lists' => function () {
                /** @var list{int, ...<int|string>}&list{int, ...<int|bool>} */
                $payload = json_decode('[1]', true);

                return $payload;
            },
        ];

        $this->assertSame(
            [
                'shapes' => self::ERROR_WITH_INTEGER_ENTRIES,
                'lists' => '{"type":"array","items":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
        $this->assertSame(
            [
                'shapes' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: string|number',
                    '}',
                ]),
                'lists' => 'number[]',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function anIntersectionOfShapesKeepsOtherEntriesThatOnlyOneMemberDescribes(): void
    {
        $closures = [
            'closedFirstMember' => function () {
                /** @var array{code: int}&array{error: string, ...<string, int>} */
                $payload = json_decode('{"error":"failed","code":1}', true);

                return $payload;
            },
            'anyTypeInTheFirstMember' => function () {
                /** @var array{code: int, ...}&array{error: string, ...<string, int>} */
                $payload = json_decode('{"error":"failed","code":1}', true);

                return $payload;
            },
            'anyTypeInTheSecondMember' => function () {
                /** @var array{error: string, ...<string, int>}&array{code: int, ...} */
                $payload = json_decode('{"error":"failed","code":1}', true);

                return $payload;
            },
        ];

        $codeThenError = '{"type":"object","properties":{"code":{"type":"integer"},"error":{"type":"string"}},"required":["code","error"],"additionalProperties":{"type":"integer"}}';

        $this->assertSame(
            [
                'closedFirstMember' => $codeThenError,
                'anyTypeInTheFirstMember' => $codeThenError,
                'anyTypeInTheSecondMember' => '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error","code"],"additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aShapeNextToAMapStaysASeparateUnionMember(): void
    {
        $closure = fn (bool $flag) => $flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::countsByName();

        $this->assertSame(
            '{"anyOf":[' . self::ERROR_WITH_INTEGER_ENTRIES . ',{"type":"object","additionalProperties":{"type":"integer"}}]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function knownKeysNextToAMapStayKnown(): void
    {
        $closures = [
            'spreadOfUnknownValuesAfterAKey' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::mixedByName()],
            'spreadOfIntegersAfterAKey' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::countsByName()],
            'spreadOfAListAfterAKey' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::ints()],
            'spreadOfUnknownValuesBeforeAKey' => fn (string $message) => [...OpenArrayShapeTest::mixedByName(), 'error' => $message],
            'spreadOfIntegersBeforeAKey' => fn (string $message) => [...OpenArrayShapeTest::countsByName(), 'error' => $message],
            'mergeOfUnknownValuesAfterAKey' => fn (string $message) => array_merge(['error' => $message], OpenArrayShapeTest::mixedByName()),
            'mergeOfIntegersAfterAKey' => fn (string $message) => array_merge(['error' => $message], OpenArrayShapeTest::countsByName()),
            'mergeOfAListAfterAKey' => fn (string $message) => array_merge(['error' => $message], OpenArrayShapeTest::ints()),
            'mergeOfIntegersBeforeAKey' => fn (string $message) => array_merge(OpenArrayShapeTest::countsByName(), ['error' => $message]),
        ];

        $this->assertSame(
            [
                'spreadOfUnknownValuesAfterAKey' => self::ERROR_WITH_ANY_ENTRIES,
                'spreadOfIntegersAfterAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'spreadOfAListAfterAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'spreadOfUnknownValuesBeforeAKey' => self::ERROR_WITH_ANY_ENTRIES,
                'spreadOfIntegersBeforeAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'mergeOfUnknownValuesAfterAKey' => self::ERROR_WITH_ANY_ENTRIES,
                'mergeOfIntegersAfterAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'mergeOfAListAfterAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'mergeOfIntegersBeforeAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aListAfterATupleKeepsTheTuplesPositions(): void
    {
        $closures = [
            'spread' => fn () => [...OpenArrayShapeTest::nameAndId(), ...OpenArrayShapeTest::ints()],
            'merge' => fn () => array_merge(OpenArrayShapeTest::nameAndId(), OpenArrayShapeTest::ints()),
            'spreadOfATupleWithOtherEntries' => fn () => [...OpenArrayShapeTest::idNameAndFlags()],
            'spreadOfATupleWithOtherEntriesAndAList' => fn () => [...OpenArrayShapeTest::idNameAndFlags(), ...OpenArrayShapeTest::ints()],
            'mergeOfATupleWithOtherEntriesAndAList' => fn () => array_merge(OpenArrayShapeTest::idNameAndFlags(), OpenArrayShapeTest::ints()),
        ];

        $nameAndIdBeforeIntegers = '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"}],"items":{"type":"integer"},"minItems":2}';
        $idAndNameBeforeBooleansAndIntegers = '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":["boolean","integer"]},"minItems":2}';

        $this->assertSame(
            [
                'spread' => $nameAndIdBeforeIntegers,
                'merge' => $nameAndIdBeforeIntegers,
                'spreadOfATupleWithOtherEntries' => self::ID_AND_NAME_BEFORE_BOOLEANS,
                'spreadOfATupleWithOtherEntriesAndAList' => $idAndNameBeforeBooleansAndIntegers,
                'mergeOfATupleWithOtherEntriesAndAList' => $idAndNameBeforeBooleansAndIntegers,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function entriesAfterIntKeyedEntriesOfUnknownCountHaveNoKnownIndex(): void
    {
        $closures = [
            'tupleAfterAList' => fn () => [...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::nameAndId()],
            'mergeOfATupleAfterAList' => fn () => array_merge(OpenArrayShapeTest::ints(), OpenArrayShapeTest::nameAndId()),
            'tupleAfterATupleWithOtherEntries' => fn () => [...OpenArrayShapeTest::idNameAndFlags(), ...OpenArrayShapeTest::nameAndId()],
            'tupleAfterAMapOfAnyKey' => fn () => [...OpenArrayShapeTest::arrayKeyedNullableInts(), ...OpenArrayShapeTest::nameAndId()],
            'keyAfterTheTuple' => fn (string $message) => [...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::nameAndId(), 'error' => $message],
            'tupleAfterAStringKeyedMap' => fn () => [...OpenArrayShapeTest::countsByName(), ...OpenArrayShapeTest::nameAndId()],
        ];

        $this->assertSame(
            [
                'tupleAfterAList' => '{"type":"array","items":{"type":["integer","string"]}}',
                'mergeOfATupleAfterAList' => '{"type":"array","items":{"type":["integer","string"]}}',
                'tupleAfterATupleWithOtherEntries' => '{"type":"array","items":{"type":["integer","string","boolean"]}}',
                'tupleAfterAMapOfAnyKey' => '{"type":"object","additionalProperties":{"type":["integer","string","null"]}}',
                'keyAfterTheTuple' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":["integer","string"]}}',
                'tupleAfterAStringKeyedMap' => '{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"integer"}},"required":["0","1"],"additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function otherEntriesMayTakeTheIndexOfAMissingTupleEntry(): void
    {
        $closures = [
            'spread' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints()],
            'merge' => fn () => array_merge(OpenArrayShapeTest::nameAndOptionalFlag(), OpenArrayShapeTest::ints()),
            'spreadOfAUnionOfATupleAndAList' => fn (bool $flag) => [...($flag ? OpenArrayShapeTest::nameAndId() : OpenArrayShapeTest::ints())],
        ];

        $this->assertSame(
            [
                'spread' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","integer"]}],"items":{"type":"integer"},"minItems":1}',
                'merge' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","integer"]}],"items":{"type":"integer"},"minItems":1}',
                'spreadOfAUnionOfATupleAndAList' => '{"type":"array","prefixItems":[{"type":["string","integer"]},{"type":"integer"}],"items":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function tupleEntriesAfterAListMayTakeTheIndexOfAMissingTupleEntry(): void
    {
        $closures = [
            'spread' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::point()],
            'merge' => fn () => array_merge(OpenArrayShapeTest::nameAndOptionalFlag(), OpenArrayShapeTest::ints(), OpenArrayShapeTest::point()),
            'twoMissingTupleEntries' => fn () => [...OpenArrayShapeTest::nameOptionalFlagAndOptionalNull(), ...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::point()],
            'unionSource' => fn (bool $flag) => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints(), ...($flag ? OpenArrayShapeTest::point() : OpenArrayShapeTest::idAndName())],
            'sourceWithAStringKey' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::amountAndLabel()],
        ];

        $this->assertSame(
            [
                'spread' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","number"]}],"items":{"type":"number"},"minItems":1}',
                'merge' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","number"]}],"items":{"type":"number"},"minItems":1}',
                'twoMissingTupleEntries' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","number","null"]},{"type":["number","null"]}],"items":{"type":"number"},"minItems":1}',
                'unionSource' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","number","string"]}],"items":{"type":["number","string"]},"minItems":1}',
                'sourceWithAStringKey' => '{"type":"object","properties":{"0":{"type":"string"},"1":{"type":["boolean","number"]},"label":{"type":"string"}},"required":["0","label"],"additionalProperties":{"type":"number"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function keylessItemsAfterAListMayTakeTheIndexOfAMissingTupleEntry(): void
    {
        $config = self::loadConfig();
        $config->data['arrays']['resolve_partial_shapes'] = true;

        $closures = [
            'keylessItem' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints(), 1.5],
            'keylessItemOfUnknownType' => fn (mixed $value) => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints(), $value],
        ];

        $this->assertSame(
            [
                'keylessItem' => '{"type":"array","prefixItems":[{"type":"string"},{"type":["boolean","number"]}],"items":{"type":"number"},"minItems":1}',
                'keylessItemOfUnknownType' => '{"type":"array","prefixItems":[{"type":"string"},{"type":"string"}],"minItems":1}',
            ],
            array_map(fn (Closure $closure) => $this->closureReturnSchema($closure, $config), $closures),
        );
    }


    #[Test]
    public function tupleEntriesWithAKnownIndexDoNotReachTheOtherTupleEntries(): void
    {
        $closures = [
            'requiredTupleBeforeTheList' => fn () => [...OpenArrayShapeTest::nameAndId(), ...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::point()],
            'stringKeyedMapBeforeTheTuple' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::countsByName(), ...OpenArrayShapeTest::point()],
            'noListBeforeTheTuple' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::point()],
        ];

        $this->assertSame(
            [
                'requiredTupleBeforeTheList' => '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"}],"items":{"type":"number"},"minItems":2}',
                'stringKeyedMapBeforeTheTuple' => '{"type":"object","properties":{"0":{"type":"string"},"1":{"anyOf":[{"type":"number","format":"float"},{"type":"boolean"}]},"2":{"type":"number","format":"float"},"3":{"type":"number","format":"float"}},"required":["0","1","2"],"additionalProperties":{"type":"integer"}}',
                'noListBeforeTheTuple' => '{"type":"array","prefixItems":[{"type":"string"},{"anyOf":[{"type":"number","format":"float"},{"type":"boolean"}]},{"type":"number","format":"float"},{"type":"number","format":"float"}],"minItems":3,"maxItems":4}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function tupleEntriesAfterAListMayTakeTheIndexOfAMissingTupleEntryInTypeScript(): void
    {
        $closures = [
            'spread' => fn () => [...OpenArrayShapeTest::nameAndOptionalFlag(), ...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::nameAndId()],
        ];

        $this->assertSame(
            [
                'spread' => '[string, (boolean|number|string)?, ...Array<number|string>]',
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aSpreadOrMergeKeepsTheOtherEntriesOfItsSource(): void
    {
        $closures = [
            'spread' => fn () => [...OpenArrayShapeTest::errorWithCounts()],
            'spreadOfUnknownEntries' => fn () => [...OpenArrayShapeTest::errorWithUnknownEntries()],
            'spreadAfterAKey' => fn (int $code) => ['code' => $code, ...OpenArrayShapeTest::errorWithCounts()],
            'spreadBeforeAKey' => fn (int $code) => [...OpenArrayShapeTest::errorWithCounts(), 'code' => $code],
            'mergeAfterAKey' => fn (int $code) => array_merge(['code' => $code], OpenArrayShapeTest::errorWithCounts()),
            'spreadOfATupleWithOtherEntriesBeforeAKey' => fn (string $label) => [...OpenArrayShapeTest::idNameAndFlags(), 'label' => $label],
        ];

        $this->assertSame(
            [
                'spread' => self::ERROR_WITH_INTEGER_ENTRIES,
                'spreadOfUnknownEntries' => self::ERROR_WITH_ANY_ENTRIES,
                'spreadAfterAKey' => '{"type":"object","properties":{"code":{"type":"integer"},"error":{"type":"string"}},"required":["code","error"],"additionalProperties":{"type":"integer"}}',
                'spreadBeforeAKey' => '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error","code"],"additionalProperties":{"type":"integer"}}',
                'mergeAfterAKey' => '{"type":"object","properties":{"code":{"type":"integer"},"error":{"type":"string"}},"required":["code","error"],"additionalProperties":{"type":"integer"}}',
                'spreadOfATupleWithOtherEntriesBeforeAKey' => '{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"},"label":{"type":"string"}},"required":["0","1","label"],"additionalProperties":{"type":"boolean"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aUnionSourceKeepsKnownKeysAndTheOtherEntriesOfEveryMember(): void
    {
        $closures = [
            'spreadOfAMapOrAShape' => fn (string $message, bool $flag) => ['error' => $message, ...($flag ? OpenArrayShapeTest::countsByName() : ['code' => 1])],
            'spreadOfAMapOfUnknownValuesOrAShape' => fn (string $message, bool $flag) => ['error' => $message, ...($flag ? OpenArrayShapeTest::mixedByName() : ['code' => 1])],
            'spreadOfShapesWithOtherEntries' => fn (bool $flag) => [...($flag ? OpenArrayShapeTest::errorWithCounts() : OpenArrayShapeTest::errorWithFlags())],
            'mergeOfAMapOrAShape' => fn (string $message, bool $flag) => array_merge(['error' => $message], $flag ? OpenArrayShapeTest::countsByName() : ['code' => 1]),
        ];

        $errorAndOptionalCodeWithIntegerEntries = '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer","const":1}},"required":["error"],"additionalProperties":{"type":"integer"}}';

        $this->assertSame(
            [
                'spreadOfAMapOrAShape' => $errorAndOptionalCodeWithIntegerEntries,
                'spreadOfAMapOfUnknownValuesOrAShape' => '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer","const":1}},"required":["error"],"additionalProperties":true}',
                'spreadOfShapesWithOtherEntries' => self::ERROR_WITH_INTEGER_OR_BOOLEAN_ENTRIES,
                'mergeOfAMapOrAShape' => $errorAndOptionalCodeWithIntegerEntries,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aSourceOfUnknownEntriesAddsEntriesOfAnyType(): void
    {
        $closures = [
            'spreadOfAnUntypedArray' => fn (string $message, array $extra) => ['error' => $message, ...$extra],
            'spreadOfANonEmptyArray' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::nonEmptyArray()],
            // @phpstan-ignore arrayUnpacking.nonIterable
            'spreadOfAnUnknownValue' => fn (string $message, mixed $extra) => ['error' => $message, ...$extra],
            'mergeOfAnUntypedArray' => fn (string $message, array $extra) => array_merge(['error' => $message], $extra),
            // @phpstan-ignore argument.type
            'mergeOfAnUnknownValue' => fn (string $message, mixed $extra) => array_merge(['error' => $message], $extra),
            'spreadOfAnUntypedArrayOrAnEmptyArray' => fn (string $message, bool $flag, array $extra) => ['error' => $message, ...($flag ? $extra : [])],
            'spreadOfAnEmptyArrayOrAnUntypedArray' => fn (string $message, bool $flag, array $extra) => ['error' => $message, ...($flag ? [] : $extra)],
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::ERROR_WITH_ANY_ENTRIES),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function entriesAfterASourceOfUnknownEntriesHaveNoKnownIndex(): void
    {
        $closures = [
            'tupleAfterAnUntypedArray' => fn (array $extra) => [...$extra, ...OpenArrayShapeTest::nameAndId()],
            'tupleBeforeAnUntypedArray' => fn (array $extra) => [...OpenArrayShapeTest::nameAndId(), ...$extra],
            'keylessItemAfterAnUntypedArray' => fn (array $names) => [...$names, 'all'],
        ];

        $this->assertSame(
            [
                'tupleAfterAnUntypedArray' => '{"type":"array","items":{"type":"string"}}',
                'tupleBeforeAnUntypedArray' => '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"}],"minItems":2}',
                'keylessItemAfterAnUntypedArray' => '{"type":"array","items":{"type":"string"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anEmptyArrayAddsNothing(): void
    {
        $closures = [
            'spreadOfAnEmptyLiteral' => fn (string $message) => ['error' => $message, ...[]],
            'mergeOfAnEmptyLiteral' => fn (string $message) => array_merge(['error' => $message], []),
            'spreadOfAnEmptyConstant' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::NO_ENTRIES],
            'spreadOfAnEmptyDeclaredShape' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::emptyShape()],
            'spreadOfAVariableHoldingAnEmptyArray' => function (string $message) {
                $extra = [];

                return ['error' => $message, ...$extra];
            },
            'spreadOfAnArrayWhoseOnlyKeyWasUnset' => function (string $message) {
                $extra = ['code' => 1];
                unset($extra['code']);

                return ['error' => $message, ...$extra];
            },
            'mergeOfAnArrayWhoseOnlyKeyWasUnset' => function (string $message) {
                $extra = ['code' => 1];
                unset($extra['code']);

                return array_merge(['error' => $message], $extra);
            },
            'spreadOfADisjointKeyIntersection' => fn (string $message) => ['error' => $message, ...array_intersect_key(['code' => 1], ['id' => 2])],
            'spreadOfAKeyDiffThatRemovesEveryKey' => fn (string $message) => ['error' => $message, ...array_diff_key(['code' => 1], ['code' => 2])],
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"]}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aMergeOrLiteralOfOnlyEmptyArraysIsEmpty(): void
    {
        $closures = [
            'spreadOfAMergeOfEmptyArrays' => fn (string $message) => ['error' => $message, ...array_merge([], [])],
            'spreadOfAMergeWithoutArguments' => fn (string $message) => ['error' => $message, ...array_merge()],
            'mergeOfAMergeOfEmptyArrays' => fn (string $message) => array_merge(['error' => $message], array_merge([], [])),
            'spreadOfALiteralOfEmptySpreads' => fn (string $message) => ['error' => $message, ...[...[], ...[]]],
            'spreadOfALiteralOfAnEmptyConstant' => fn (string $message) => ['error' => $message, ...[...OpenArrayShapeTest::NO_ENTRIES]],
            'spreadOfAVariableHoldingAMergeOfEmptyArrays' => function (string $message) {
                $extra = array_merge([], []);

                return ['error' => $message, ...$extra];
            },
            'spreadOfALiteralWithOnlyIgnoredItems' => fn (string $message) => ['error' => $message, ...[
                /** @autodoc-ignore */
                'debug' => true,
            ]],
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"]}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aMergeOrLiteralWithEntriesNextToEmptyArraysIsNotEmpty(): void
    {
        $closures = [
            'mergeOfAnEmptyAndAnUntypedArray' => fn (string $message, array $extra) => ['error' => $message, ...array_merge([], $extra)],
            'spreadOfALiteralWithAKeyOfUnknownValue' => fn (string $message, string $key) => ['error' => $message, ...[$key => 1]],
            'keyAfterAnEmptySpread' => fn () => [...[], 'a' => 2],
            'listAfterAnEmptySpread' => fn () => [...[], ...OpenArrayShapeTest::ints()],
            'mergeOfAnEmptyArrayAndAList' => fn () => array_merge([], OpenArrayShapeTest::ints()),
            'ignoredItemNextToAKeptItem' => fn () => [
                /** @autodoc-ignore */
                'debug' => true,
                'a' => 2,
            ],
            'mergeOfEmptyArraysAlone' => fn () => array_merge([], []),
            'literalOfEmptySpreadsAlone' => fn () => [...[], ...[]],
        ];

        $this->assertSame(
            [
                'mergeOfAnEmptyAndAnUntypedArray' => self::ERROR_WITH_ANY_ENTRIES,
                'spreadOfALiteralWithAKeyOfUnknownValue' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer","const":1}}',
                'keyAfterAnEmptySpread' => '{"type":"object","properties":{"a":{"type":"integer","const":2}},"required":["a"]}',
                'listAfterAnEmptySpread' => '{"type":"array","items":{"type":"integer"}}',
                'mergeOfAnEmptyArrayAndAList' => '{"type":"array","items":{"type":"integer"}}',
                'ignoredItemNextToAKeptItem' => '{"type":"object","properties":{"a":{"type":"integer","const":2}},"required":["a"]}',
                'mergeOfEmptyArraysAlone' => '{"type":"array","items":{"type":"string"}}',
                'literalOfEmptySpreadsAlone' => '{"type":"array","items":{"type":"string"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anEmptyArrayInAUnionMakesTheOtherKeysOptional(): void
    {
        $closures = [
            'ternary' => fn (string $message, bool $flag, string $details) => ['error' => $message, ...($flag ? ['details' => $details] : [])],
            'filledInABranch' => function (string $message, bool $flag, string $details) {
                $extra = [];

                if ($flag) {
                    $extra['details'] = $details;
                }

                return ['error' => $message, ...$extra];
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"object","properties":{"error":{"type":"string"},"details":{"type":"string"}},"required":["error"]}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anArrayThatStartsEmptyIsNoLongerEmptyOnceFilled(): void
    {
        $closures = [
            'appended' => function (bool $flag) {
                $ids = [];

                if ($flag) {
                    $ids[] = 5;
                }

                if ($ids) {
                    return $ids;
                }

                return [];
            },
            'writtenUnderAKey' => function () {
                $counts = [];
                $counts['total'] = 5;

                return ['error' => 'failed', ...$counts];
            },
        ];

        $this->assertSame(
            [
                'appended' => '{"type":"array","items":{"type":"integer","const":5}}',
                'writtenUnderAKey' => '{"type":"object","properties":{"error":{"type":"string","const":"failed"},"total":{"type":"integer","const":5}},"required":["error","total"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anArrayThatStartsEmptyMayBeFilledThroughAReference(): void
    {
        $closures = [
            'filledByABuiltInFunction' => function (bool $failed) {
                $errors = [];

                if ($failed) {
                    array_push($errors, 'failed');
                }

                if ($errors) {
                    return $errors;
                }

                return 'none';
            },
            'filledAsAnOutParameter' => function (string $code) {
                $matches = [];
                preg_match('/(\d+)/', $code, $matches);

                if ($matches) {
                    return $matches;
                }

                return 'none';
            },
            'filledByAMethod' => function () {
                $errors = [];
                OpenArrayShapeTest::collectErrors($errors);

                if (! empty($errors)) {
                    return $errors;
                }

                return 'none';
            },
            'filledByAClosure' => function () {
                $errors = [];
                $collect = function () use (&$errors) {
                    $errors[] = 'failed';
                };
                $collect();

                if ($errors) {
                    return $errors;
                }

                return 'none';
            },
            'filledThroughAReference' => function (bool $failed) {
                $errors = [];
                $reference = &$errors;

                if ($failed) {
                    $reference[] = 'failed';
                }

                if ($errors) {
                    return $errors;
                }

                return 'none';
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"anyOf":[{"type":"array","items":{"type":"string"}},{"type":"string","const":"none"}]}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function anIntKeyBeforeOtherEntriesIsNotAListIndex(): void
    {
        $closures = [
            'spreadAfterKeyThree' => fn () => [3 => 'a', ...OpenArrayShapeTest::ints()],
            'spreadAfterKeyZero' => fn () => [0 => 'a', ...OpenArrayShapeTest::ints()],
            'mergeAfterKeyThree' => fn () => array_merge([3 => 'a'], OpenArrayShapeTest::ints()),
        ];

        $aBeforeIntegers = '{"type":"array","prefixItems":[{"type":"string","const":"a"}],"items":{"type":"integer"},"minItems":1}';

        $this->assertSame(
            [
                'spreadAfterKeyThree' => '{"type":"object","properties":{"3":{"type":"string","const":"a"}},"required":["3"],"additionalProperties":{"type":"integer"}}',
                'spreadAfterKeyZero' => $aBeforeIntegers,
                'mergeAfterKeyThree' => $aBeforeIntegers,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aKeyWithSeveralPossibleValuesKeepsTheOtherEntries(): void
    {
        $closure = fn (bool $flag) => [($flag ? 'a' : 'b') => 1, ...OpenArrayShapeTest::countsByName()];

        $this->assertSame(
            '{"anyOf":['
                . '{"type":"object","properties":{"a":{"type":"integer","const":1}},"required":["a"],"additionalProperties":{"type":"integer"}},'
                . '{"type":"object","properties":{"b":{"type":"integer","const":1}},"required":["b"],"additionalProperties":{"type":"integer"}}'
                . ']}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aKeyOfUnknownValueStillFoldsTheLiteral(): void
    {
        $closures = [
            'dynamicKeyBeforeAMap' => fn (string $key, string $message) => [$key => $message, ...OpenArrayShapeTest::countsByName()],
            'keylessItemBeforeAMap' => fn (int $id) => [$id, ...OpenArrayShapeTest::countsByName()],
        ];

        $this->assertSame(
            [
                'dynamicKeyBeforeAMap' => '{"type":"object","additionalProperties":{"type":["integer","string"]}}',
                'keylessItemBeforeAMap' => '{"type":"object","additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aKeyOfUnknownValueNextToAKnownKeyFoldsTheLiteral(): void
    {
        $closures = [
            'mixedKey' => fn (mixed $key) => [$key => 1, 'a' => 'x'], // @phpstan-ignore array.invalidKey
            'nullableStringKey' => fn (?string $key) => [$key => 1, 'a' => 'x'], // @phpstan-ignore array.invalidKey
            'floatKey' => fn (float $key) => [$key => 1, 'a' => 'x'], // @phpstan-ignore array.invalidKey
            'intOrStringKey' => fn (int|string $key) => [$key => 1, 'a' => 'x'],
            'knownKeyFirst' => fn (mixed $key) => ['a' => 'x', $key => 1], // @phpstan-ignore array.invalidKey
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"object","additionalProperties":{"anyOf":[{"type":"string","const":"x"},{"type":"integer","const":1}]}}'),
            array_map($this->closureReturnSchema(...), $closures),
        );

        $this->assertSame(
            array_fill_keys(array_keys($closures), "Record<string, 'x'|1>"),
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function partialShapesKeepTheOtherEntriesToo(): void
    {
        $config = self::loadConfig();
        $config->data['arrays']['resolve_partial_shapes'] = true;

        $closures = [
            'mapAfterAKey' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::countsByName()],
            'listAfterATuple' => fn () => [...OpenArrayShapeTest::nameAndId(), ...OpenArrayShapeTest::ints()],
            'tupleAfterAList' => fn () => [...OpenArrayShapeTest::ints(), ...OpenArrayShapeTest::nameAndId()],
            'keylessItemAfterAList' => fn () => [...OpenArrayShapeTest::ints(), 'all'],
            'keylessItemAfterAStringKeyedMap' => fn () => [...OpenArrayShapeTest::countsByName(), 'all'],
            'keylessItemAfterATuple' => fn () => [...OpenArrayShapeTest::nameAndId(), 'all'],
        ];

        $this->assertSame(
            [
                'mapAfterAKey' => self::ERROR_WITH_INTEGER_ENTRIES,
                'listAfterATuple' => '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"}],"items":{"type":"integer"},"minItems":2}',
                'tupleAfterAList' => '{"type":"array","items":{"type":["integer","string"]}}',
                'keylessItemAfterAList' => '{"type":"array","items":{"anyOf":[{"type":"string","const":"all"},{"type":"integer"}]}}',
                'keylessItemAfterAStringKeyedMap' => '{"type":"object","properties":{"0":{"type":"string","const":"all"}},"required":["0"],"additionalProperties":{"type":"integer"}}',
                'keylessItemAfterATuple' => '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"},{"type":"string","const":"all"}],"minItems":3,"maxItems":3}',
            ],
            array_map(fn (Closure $closure) => $this->closureReturnSchema($closure, $config), $closures),
        );
    }


    #[Test]
    public function aSpreadInANestedLiteralKeepsItsKnownKeys(): void
    {
        $closures = [
            'nested' => fn (string $message) => ['data' => ['error' => $message, ...OpenArrayShapeTest::countsByName()]],
            'spreadOfALiteralWithOtherEntries' => fn (string $message) => [...['error' => $message, ...OpenArrayShapeTest::countsByName()]],
        ];

        $this->assertSame(
            [
                'nested' => '{"type":"object","properties":{"data":' . self::ERROR_WITH_INTEGER_ENTRIES . '},"required":["data"]}',
                'spreadOfALiteralWithOtherEntries' => self::ERROR_WITH_INTEGER_ENTRIES,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function readingALiteralWithOtherEntriesSeesThem(): void
    {
        $closures = [
            'aKeyOutsideTheShape' => function (string $message) {
                $payload = ['error' => $message, ...OpenArrayShapeTest::countsByName()];

                return $payload['count'];
            },
            'aKnownKey' => function (string $message) {
                $payload = ['error' => $message, ...OpenArrayShapeTest::countsByName()];

                return $payload['error'];
            },
        ];

        $this->assertSame(
            [
                'aKeyOutsideTheShape' => '{"type":"integer"}',
                'aKnownKey' => '{"type":"string"}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function literalsWithOtherEntriesHaveAnIndexSignatureOrARestElementInTypeScript(): void
    {
        $closures = [
            'unknownEntries' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::mixedByName()],
            'typedEntries' => fn (string $message) => ['error' => $message, ...OpenArrayShapeTest::countsByName()],
            'listAfterATuple' => fn () => [...OpenArrayShapeTest::nameAndId(), ...OpenArrayShapeTest::ints()],
            'untypedArray' => fn (string $message, array $extra) => ['error' => $message, ...$extra],
            'emptyArray' => fn (string $message) => ['error' => $message, ...[]],
        ];

        $this->assertSame(
            [
                'unknownEntries' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: unknown',
                    '}',
                ]),
                'typedEntries' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: string|number',
                    '}',
                ]),
                'listAfterATuple' => '[string, number, ...number[]]',
                'untypedArray' => implode("\n", [
                    '{',
                    '    error: string',
                    '    [key: string]: unknown',
                    '}',
                ]),
                'emptyArray' => implode("\n", [
                    '{',
                    '    error: string',
                    '}',
                ]),
            ],
            array_map($this->closureReturnTypeScript(...), $closures),
        );
    }


    #[Test]
    public function aConditionalTypeChecksTheOtherEntriesOfAnOpenShape(): void
    {
        $closures = [
            'sameShape' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(OpenArrayShapeTest::errorWithCounts()),
            'otherEntriesOfAnotherType' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(OpenArrayShapeTest::errorWithFlags()),
            'otherEntriesOfUnknownType' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(OpenArrayShapeTest::errorWithUnknownEntries()),
            'otherEntriesUnderIntKeys' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(OpenArrayShapeTest::errorWithIntKeyedFlags()),
            'extraKeyOfTheOtherEntriesType' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(['error' => 'x', 'count' => 5]),
            'extraKeyOfAnotherType' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(['error' => 'x', 'name' => 'y']),
            'extraIntKey' => fn () => OpenArrayShapeTest::classifyErrorWithCounts(['error' => 'x', 5 => 1]),
        ];

        $this->assertSame(
            [
                'sameShape' => '{"type":"string","const":"counts"}',
                'otherEntriesOfAnotherType' => '{"type":"string","enum":["counts","other"]}',
                'otherEntriesOfUnknownType' => '{"type":"string","enum":["counts","other"]}',
                'otherEntriesUnderIntKeys' => '{"type":"string","enum":["counts","other"]}',
                'extraKeyOfTheOtherEntriesType' => '{"type":"string","const":"counts"}',
                'extraKeyOfAnotherType' => '{"type":"string","const":"other"}',
                'extraIntKey' => '{"type":"string","const":"other"}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aConditionalTypeChecksEveryEntryOfAShapeAgainstAMap(): void
    {
        $closures = [
            'openShapeWithAKnownKeyOfAnotherType' => fn () => OpenArrayShapeTest::classifyIntsByName(OpenArrayShapeTest::errorWithCounts()),
            'closedShapeOfAnotherType' => fn () => OpenArrayShapeTest::classifyIntsByName(['error' => 'x']),
            'closedShapeOfTheMapsType' => fn () => OpenArrayShapeTest::classifyIntsByName(['count' => 1, 'total' => 2]),
            'map' => fn () => OpenArrayShapeTest::classifyIntsByName(OpenArrayShapeTest::countsByName()),
        ];

        $this->assertSame(
            [
                'openShapeWithAKnownKeyOfAnotherType' => '{"type":"string","const":"other"}',
                'closedShapeOfAnotherType' => '{"type":"string","const":"other"}',
                'closedShapeOfTheMapsType' => '{"type":"string","const":"ints"}',
                'map' => '{"type":"string","const":"ints"}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aConditionalTypeAllowsAMissingOptionalKey(): void
    {
        $closures = [
            'missingOptionalKey' => fn () => OpenArrayShapeTest::classifyErrorWithOptionalDetails(['error' => 'x']),
            'optionalKeyOfItsType' => fn () => OpenArrayShapeTest::classifyErrorWithOptionalDetails(['error' => 'x', 'details' => 'y']),
            'optionalKeyOfAnotherType' => fn () => OpenArrayShapeTest::classifyErrorWithOptionalDetails(['error' => 'x', 'details' => 5]),
            'otherEntriesThatMayHoldTheKey' => fn () => OpenArrayShapeTest::classifyErrorWithOptionalDetails(OpenArrayShapeTest::errorWithCounts()),
            'otherEntriesThatCanNotHoldTheKey' => fn () => OpenArrayShapeTest::classifyErrorWithOptionalDetails(OpenArrayShapeTest::errorWithIntKeyedFlags()),
            'missingRequiredKey' => fn () => OpenArrayShapeTest::classifyErrorWithOptionalDetails(['details' => 'y']),
            'mapAgainstOptionalKeysAndOtherEntries' => fn () => OpenArrayShapeTest::classifyOptionalTotalWithCounts(OpenArrayShapeTest::countsByName()),
            'emptyArrayAgainstOptionalKeys' => fn () => OpenArrayShapeTest::classifyOptionalTotalWithCounts([]),
        ];

        $this->assertSame(
            [
                'missingOptionalKey' => '{"type":"string","const":"error"}',
                'optionalKeyOfItsType' => '{"type":"string","const":"error"}',
                'optionalKeyOfAnotherType' => '{"type":"string","const":"other"}',
                'otherEntriesThatMayHoldTheKey' => '{"type":"string","enum":["error","other"]}',
                'otherEntriesThatCanNotHoldTheKey' => '{"type":"string","const":"error"}',
                'missingRequiredKey' => '{"type":"string","enum":["error","other"]}',
                'mapAgainstOptionalKeysAndOtherEntries' => '{"type":"string","const":"counts"}',
                'emptyArrayAgainstOptionalKeys' => '{"type":"string","const":"counts"}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function aConditionalTypeOnAClosedShapeAllowsExtraKeys(): void
    {
        $closures = [
            'sameShape' => fn () => OpenArrayShapeTest::classifyError(OpenArrayShapeTest::errorOnly()),
            'extraKey' => fn () => OpenArrayShapeTest::classifyError(['error' => 'x', 'code' => 1]),
            'otherEntries' => fn () => OpenArrayShapeTest::classifyError(OpenArrayShapeTest::errorWithFlags()),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"string","const":"error"}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function deepShapeInferenceKeepsAnAnalyzedShapeThatDoesNotFitThePhpDocMap(): void
    {
        $config = self::loadConfig();
        $config->data['arrays']['deep_shape_inference'] = true;

        $this->assertSame(
            [
                'withDeepShapeInference' => '{"type":"object","properties":{"data":{"type":"object"}},"required":["data"]}',
                'withoutDeepShapeInference' => '{"type":"object","additionalProperties":{"type":"string"}}',
            ],
            [
                'withDeepShapeInference' => $this->closureReturnSchema(fn () => OpenArrayShapeTest::rulesWithADeclaredFieldType(), $config),
                'withoutDeepShapeInference' => $this->closureReturnSchema(fn () => OpenArrayShapeTest::rulesWithADeclaredFieldType()),
            ],
        );
    }


    private function closureReturnSchema(Closure $closure, ?Config $config = null): string
    {
        $config ??= self::loadConfig();

        $type = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return json_encode(SchemaJson::castPropertiesToObjects($type->toSchema($config)), JSON_THROW_ON_ERROR);
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


    /**
     * @return list<int|string>
     */
    private static function intsOrStrings(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array<int, int|null>
     */
    private static function intKeyedNullableInts(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array<int|null>
     */
    private static function nullableIntItems(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<mixed>
     */
    private static function mixedList(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<array{id: int, email: string|null}>
     */
    private static function users(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{scores: list<int|null>, total: int}
     */
    private static function ranking(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return non-empty-list<int|null>
     */
    private static function nonEmptyNullableInts(): array
    {
        return json_decode('[1]', true);
    }


    /**
     * @return array{int|null, string}
     */
    private static function nullableIntAndString(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array<array-key, int|null>
     */
    private static function arrayKeyedNullableInts(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{error: string, ...}
     */
    private static function errorWithUnknownEntries(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{error: string, ...<string, int>}
     */
    private static function errorWithCounts(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return ErrorWithCounts
     */
    private static function errorWithCountsFromAlias(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{
     *     error: string,
     *     details?: string,
     *     ...<string, int>,
     * }
     */
    private static function errorDetailsAndCounts(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @param array{error: string, ...<string, int>} $payload
     */
    private static function passThrough(array $payload): mixed
    {
        return $payload;
    }


    /**
     * @return list<array{id: int, ...<string, string>}>
     */
    private static function rowsWithLabels(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list{int, string, ...<bool>}
     */
    private static function idNameAndFlags(): array
    {
        return json_decode('[1,"first"]', true);
    }


    /**
     * @return list{int, ...}
     */
    private static function idAndUnknownEntries(): array
    {
        return json_decode('[1]', true);
    }


    /**
     * @return array{int, string, ...<bool>}
     */
    private static function idNameAndFlagsUnderAnyKey(): array
    {
        return json_decode('[1,"first"]', true);
    }


    /**
     * @return array<string, int>
     */
    private static function countsByName(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{error: string}
     */
    private static function errorOnly(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{error: string, ...<string, bool>}
     */
    private static function errorWithFlags(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{error: string, ...<string, string>}
     */
    private static function errorWithNames(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{error: string, ...<int, bool>}
     */
    private static function errorWithIntKeyedFlags(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{error: string, code: int, ...<string, bool>}
     */
    private static function errorAndCodeWithFlags(): array
    {
        return json_decode('{"error":"failed","code":1}', true);
    }


    /**
     * @return array{error: string, ...<string, array{id: int}>}
     */
    private static function errorWithRowsOfIntegerIds(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{error: string, ...<string, array{id: string}>}
     */
    private static function errorWithRowsOfStringIds(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return list{int, string}
     */
    private static function idAndName(): array
    {
        return json_decode('[1,"first"]', true);
    }


    /**
     * @return list{string, int}
     */
    private static function nameAndId(): array
    {
        return json_decode('["first",1]', true);
    }


    /**
     * @return array{0: string, 1?: bool}
     */
    private static function nameAndOptionalFlag(): array
    {
        return json_decode('["first"]', true);
    }


    /**
     * @return ($value is array{error: string, ...<string, int>} ? 'counts' : 'other')
     */
    private static function classifyErrorWithCounts(mixed $value): string
    {
        return is_array($value) ? 'counts' : 'other';
    }


    /**
     * @return ($value is array<string, int> ? 'ints' : 'other')
     */
    private static function classifyIntsByName(mixed $value): string
    {
        return is_array($value) ? 'ints' : 'other';
    }


    /**
     * @return ($value is array{error: string, details?: string} ? 'error' : 'other')
     */
    private static function classifyErrorWithOptionalDetails(mixed $value): string
    {
        return is_array($value) ? 'error' : 'other';
    }


    /**
     * @return ($value is array{total?: int, ...<string, int>} ? 'counts' : 'other')
     */
    private static function classifyOptionalTotalWithCounts(mixed $value): string
    {
        return is_array($value) ? 'counts' : 'other';
    }


    /**
     * @return ($value is array{error: string} ? 'error' : 'other')
     */
    private static function classifyError(mixed $value): string
    {
        return is_array($value) ? 'error' : 'other';
    }


    /**
     * @return array<string, string>
     */
    private static function rulesWithADeclaredFieldType(): array
    {
        return [
            /**
             * @var object
             */
            'data' => 'required',
        ];
    }


    /**
     * @return array{0: string, 1?: bool, 2?: null}
     */
    private static function nameOptionalFlagAndOptionalNull(): array
    {
        return json_decode('["first"]', true);
    }


    /**
     * @return list{float, float}
     */
    private static function point(): array
    {
        return json_decode('[1.5,2.5]', true);
    }


    /**
     * @return array{0: float, label: string}
     */
    private static function amountAndLabel(): array
    {
        return json_decode('{"0":1.5,"label":"total"}', true);
    }


    /**
     * @return non-empty-array
     */
    private static function nonEmptyArray(): array // @phpstan-ignore missingType.iterableValue
    {
        return json_decode('{"code":1}', true);
    }


    /**
     * @return array{}
     */
    private static function emptyShape(): array
    {
        return [];
    }


    /**
     * @param list<string> $errors
     */
    private static function collectErrors(array &$errors): void
    {
        $errors[] = 'failed';
    }


    /**
     * @return list{int, string, ...<string>}
     */
    private static function idNameAndLabels(): array
    {
        return json_decode('[1,"first"]', true);
    }


    /**
     * @return list{int, string, ...}
     */
    private static function idNameAndUnknownEntries(): array
    {
        return json_decode('[1,"first"]', true);
    }


    /**
     * @return list<int>
     */
    private static function ints(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list{int, ...<int|null>}
     */
    private static function idAndNullableInts(): array
    {
        return json_decode('[1]', true);
    }


    /**
     * @return list{string, int, ...<int>}
     */
    private static function nameIdAndCounts(): array
    {
        return json_decode('["first",1]', true);
    }


    /**
     * @return array{0: int, error: string, ...<int, bool>}
     */
    private static function idErrorAndIntKeyedFlags(): array
    {
        return json_decode('{"0":1,"error":"failed"}', true);
    }


    /**
     * @return list{int, ...<bool>}
     */
    private static function idAndFlags(): array
    {
        return json_decode('[1]', true);
    }


    /**
     * @return array{error: string, ...<string, int|null>}
     */
    private static function errorWithNullableCounts(): array
    {
        return json_decode('{"error":"failed"}', true);
    }


    /**
     * @return array{admin: array{id: int, email: string|null}, ...<string, array{id: int, email: string|null}>}
     */
    private static function adminAndUsersByName(): array
    {
        return json_decode('{"admin":{"id":1,"email":null}}', true);
    }
}
