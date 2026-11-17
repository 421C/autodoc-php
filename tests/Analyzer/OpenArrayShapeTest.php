<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class OpenArrayShapeTest extends TestCase
{
    use LoadsConfig;

    private const string NULLABLE_INTEGER_LIST = '{"type":"array","items":{"type":["integer","null"]}}';
    private const string NULLABLE_INTEGER_LIST_STARTING_WITH_AN_INTEGER = '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":["integer","null"]}}';

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
    public function aListWithANarrowedFirstEntryDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(self::NULLABLE_INTEGER_LIST_STARTING_WITH_AN_INTEGER, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function aListWithTwoNarrowedLeadingEntriesDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null && $scores[1] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"integer"}],"items":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aListWithAFirstEntryNarrowedByATypeCheckDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $values = OpenArrayShapeTest::intsOrStrings();

            if (is_string($values[0])) {
                return $values;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"string"}],"items":{"type":["integer","string"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function declaredIntKeyedArraysWithANarrowedFirstEntryDocumentTheirOtherEntries(): void
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
            array_fill_keys(array_keys($closures), self::NULLABLE_INTEGER_LIST_STARTING_WITH_AN_INTEGER),
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
    public function aListWithAFirstEntryKnownToExistDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if (array_key_exists(0, $scores)) {
                return $scores;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":["integer","null"]}],"items":{"type":["integer","null"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aListOfShapesWithANarrowedFirstEntryDocumentsItsOtherEntries(): void
    {
        $closure = function () {
            $users = OpenArrayShapeTest::users();

            if ($users[0]['email'] !== null) {
                return $users;
            }

            return [];
        };

        $this->assertSame(
            '{"type":"array",'
                . '"prefixItems":[{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":"string"}},"required":["id","email"]}],'
                . '"items":{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":["string","null"]}},"required":["id","email"]}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aListNestedInAShapeDocumentsItsOtherEntriesAfterItsFirstEntryIsNarrowed(): void
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
                . '"properties":{"scores":' . self::NULLABLE_INTEGER_LIST_STARTING_WITH_AN_INTEGER . ',"total":{"type":"integer"}},'
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
            '{"type":["array","null"],"prefixItems":[{"type":"integer"}],"items":{"type":["integer","null"]},"minItems":1}',
            $this->closureReturnSchema($closure),
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
    public function aListWithANarrowedFirstEntryHasARestElementInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame('[number?, ...Array<number|null>]', $this->closureReturnTypeScript($closure));
    }


    #[Test]
    public function aListWithTwoNarrowedLeadingEntriesHasARestElementInTypeScript(): void
    {
        $closure = function () {
            $scores = OpenArrayShapeTest::nullableInts();

            if ($scores[0] !== null && $scores[1] !== null) {
                return $scores;
            }

            return [];
        };

        $this->assertSame('[number?, number?, ...Array<number|null>]', $this->closureReturnTypeScript($closure));
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
    public function aListOfShapesWithANarrowedFirstEntryHasARestElementOfTheShapeInTypeScript(): void
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
                '[',
                '    {',
                '        id: number',
                '        email: string',
                '    }?,',
                '    ...Array<{',
                '        id: number',
                '        email: string|null',
                '    }>,',
                ']',
            ]),
            $this->closureReturnTypeScript($closure),
        );
    }


    #[Test]
    public function aListNestedInAShapeHasARestElementInTypeScript(): void
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
                '    scores: [number?, ...Array<number|null>]',
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
                . self::NULLABLE_INTEGER_LIST_STARTING_WITH_AN_INTEGER . ','
                . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":["integer","null"]}}'
                . ']}',
            $this->closureReturnSchema($closure),
        );
        $this->assertSame(
            implode("\n", [
                '[number?, ...Array<number|null>]|{',
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


    private function closureReturnSchema(Closure $closure): string
    {
        $config = self::loadConfig();

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
}
