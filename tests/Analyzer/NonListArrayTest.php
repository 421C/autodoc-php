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

final class NonListArrayTest extends TestCase
{
    use LoadsConfig;

    private const string INTEGER_LIST = '{"type":"array","items":{"type":"integer"}}';
    private const string INTEGER_LIST_OR_MAP = '{"anyOf":[{"type":"array","items":{"type":"integer"}},{"type":"object","additionalProperties":{"type":"integer"}}]}';
    private const string STRING_LIST = '{"type":"array","items":{"type":"string"}}';
    private const string STRING_LIST_OR_MAP = '{"anyOf":[{"type":"array","items":{"type":"string"}},{"type":"object","additionalProperties":{"type":"string"}}]}';

    #[Test]
    public function arrayFilterWithoutACallbackMayLeaveGapsInAList(): void
    {
        $closure = fn () => array_filter(NonListArrayTest::nullableInts());

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function assigningUnderStringKeysMakesAMap(): void
    {
        $closures = [
            'fromAnArrayKey' => function () {
                $idsByEmail = [];

                foreach (NonListArrayTest::users() as $user) {
                    $idsByEmail[$user['email']] = $user['id'];
                }

                return $idsByEmail;
            },
            'fromAVariable' => function (string $email, int $id) {
                $idsByEmail = [];
                $idsByEmail[$email] = $id;

                return $idsByEmail;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"object","additionalProperties":{"type":"integer"}}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function groupingUnderStringKeysMakesAMapOfLists(): void
    {
        $closure = function () {
            $usersByEmail = [];

            foreach (NonListArrayTest::users() as $user) {
                $usersByEmail[$user['email']][] = $user;
            }

            return $usersByEmail;
        };

        $this->assertSame(
            '{"type":"object","additionalProperties":{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer"},"email":{"type":"string"}},"required":["id","email"]}}}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function assigningUnderAStringKeyMakesAListAMap(): void
    {
        $closures = [
            'list' => function (string $name) {
                $ints = NonListArrayTest::ints();
                $ints[$name] = 5;

                return $ints;
            },
            'tuple' => function (string $name) {
                $map = [0 => 'zero'];
                $map[$name] = 'x';

                return $map;
            },
            'nested' => function (string $name) {
                $groups = NonListArrayTest::intLists();
                $groups[$name][] = 5;

                return $groups;
            },
            'listWithoutAKeyType' => function (string $name) {
                $ints = NonListArrayTest::intItems();
                $ints[$name] = 5;

                return $ints;
            },
        ];

        $this->assertSame(
            [
                'list' => '{"type":"object","additionalProperties":{"type":"integer"}}',
                'tuple' => '{"type":"object","additionalProperties":{"type":"string","enum":["zero","x"]}}',
                'nested' => '{"type":"object","additionalProperties":{"type":"array","items":{"type":"integer"}}}',
                'listWithoutAKeyType' => '{"type":"object","additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function writingUnderAKeyOfTheListItselfKeepsAList(): void
    {
        $closures = [
            'items' => function () {
                $services = NonListArrayTest::services();

                foreach ($services as $key => $service) {
                    $services[$key]['displayName'] = 'x';
                }

                return $services;
            },
            'arrayOfItems' => function () {
                $services = NonListArrayTest::serviceArray();

                foreach ($services as $key => $service) {
                    $services[$key]['displayName'] = 'x';
                }

                return $services;
            },
            'values' => function () {
                $services = array_values(array_filter(NonListArrayTest::serviceList(), fn (array $service) => $service['code'] !== ''));

                foreach ($services as $key => $service) {
                    $services[$key]['displayName'] = 'x';
                }

                return $services;
            },
            'varItems' => function () {
                /** @var array{code: string, displayName?: string|null}[] $services */
                $services = array_values(array_filter(NonListArrayTest::serviceList(), fn (array $service) => $service['code'] !== ''));

                foreach ($services as $key => $service) {
                    $services[$key]['displayName'] = 'x';
                }

                return $services;
            },
            'list' => function () {
                $services = NonListArrayTest::serviceList();

                foreach ($services as $key => $service) {
                    $services[$key]['displayName'] = 'x';
                }

                return $services;
            },
            'nested' => function () {
                $data = ['services' => NonListArrayTest::services()];

                foreach ($data['services'] as $key => $service) {
                    $data['services'][$key]['displayName'] = 'x';
                }

                return $data['services'];
            },
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($closures),
                '{"type":"array","items":{"type":"object","properties":{"code":{"type":"string"},"displayName":{"type":["string","null"]}},"required":["code"]}}',
            ),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function replacingEntriesUnderKeysOfTheListItselfKeepsAList(): void
    {
        $closure = function () {
            $ints = NonListArrayTest::intItems();

            foreach ($ints as $key => $value) {
                $ints[$key] = (int) $value;
            }

            return $ints;
        };

        $this->assertSame(self::INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function theKeysOfAListWithoutAKeyTypeAreInts(): void
    {
        $closure = function () {
            $keys = [];

            foreach (NonListArrayTest::intItems() as $key => $value) {
                $keys[] = $key;
            }

            return $keys;
        };

        $this->assertSame(self::INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function theKeysOfAnUntypedArrayMayBeStrings(): void
    {
        $closure = function () {
            $keys = [];

            foreach (NonListArrayTest::untyped() as $key => $value) {
                $keys[] = $key;
            }

            return $keys;
        };

        $this->assertSame('{"type":"array","items":{"type":["integer","string"]}}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function assigningUnderAStringKeyKeepsAShapeThatIsNeverAList(): void
    {
        $closure = function (string $field, int $id) {
            $data = ['id' => $id];
            $data[$field] = $id;

            return $data;
        };

        $this->assertSame('{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"]}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function assigningUnderIntKeysOrAppendingKeepsAList(): void
    {
        $closures = [
            'intKey' => function () {
                $emailsById = [];

                foreach (NonListArrayTest::users() as $user) {
                    $emailsById[$user['id']] = $user['email'];
                }

                return $emailsById;
            },
            'append' => function () {
                $emails = [];

                foreach (NonListArrayTest::users() as $user) {
                    $emails[] = $user['email'];
                }

                return $emails;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::STRING_LIST),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function filteringANonEmptyListMayLeaveItEmpty(): void
    {
        $closures = [
            'withoutACallback' => fn () => array_filter(NonListArrayTest::nonEmptyNullableInts()),
            'withACallback' => fn () => array_filter(NonListArrayTest::nonEmptyNullableInts(), fn (?int $value) => $value !== null),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::INTEGER_LIST_OR_MAP),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayFilterWithACallbackMayLeaveGapsInAList(): void
    {
        $closure = fn () => array_filter(NonListArrayTest::ints(), fn (int $value) => $value > 1);

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function arrayFilterThatCannotRemoveAnEntryKeepsTheList(): void
    {
        /** @phpstan-ignore arrayFilter.same */
        $closure = fn () => array_filter(NonListArrayTest::objects());

        $this->assertSame('{"type":"array","items":{"type":"object"}}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function arrayMapOverOneArrayKeepsTheGaps(): void
    {
        $closure = fn () => array_map(
            fn (int $value) => (string) $value,
            array_filter(NonListArrayTest::ints(), fn (int $value) => $value > 1),
        );

        $this->assertSame(self::STRING_LIST_OR_MAP, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function renumberingAFilteredListMakesItAListAgain(): void
    {
        $closures = [
            'values' => fn () => array_values(array_filter(NonListArrayTest::nullableInts())),
            'spread' => fn () => [...array_filter(NonListArrayTest::nullableInts())],
            'merge' => fn () => array_merge(array_filter(NonListArrayTest::nullableInts()), [4]),
            'mapOverSeveralArrays' => fn () => array_map(
                fn (int $a, int $b) => (string) ($a + $b),
                array_filter(NonListArrayTest::ints()),
                NonListArrayTest::ints(),
            ),
            'sort' => function () {
                $filtered = array_filter(NonListArrayTest::nullableInts());
                sort($filtered);

                return $filtered;
            },
        ];

        $this->assertSame(
            [
                'values' => self::INTEGER_LIST,
                'spread' => self::INTEGER_LIST,
                'merge' => self::INTEGER_LIST,
                'mapOverSeveralArrays' => self::STRING_LIST,
                'sort' => self::INTEGER_LIST,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function renumberingMovesEntriesAfterAMissingOneToAnEarlierIndex(): void
    {
        $closures = [
            'spread' => fn () => [...array_filter(NonListArrayTest::nullableTuple())],
            'merge' => fn () => array_merge(array_filter(NonListArrayTest::nullablePair()), array_filter(NonListArrayTest::nullablePair())),
            'spreadAfterAnOptionalEnd' => fn () => [...NonListArrayTest::listWithOptionalEnd(), ...NonListArrayTest::listWithOptionalEnd()],
            'mergeAfterStringKeys' => fn () => array_merge(['id' => 1], array_filter(NonListArrayTest::nullablePair())),
        ];

        $this->assertSame(
            [
                'spread' => '{"type":"array","prefixItems":[{"type":["integer","string","boolean"]},{"type":["string","boolean"]},{"type":"boolean"}],"maxItems":3}',
                'merge' => '{"type":"array","prefixItems":[{"type":["integer","string"]},{"type":["string","integer"]},{"type":["integer","string"]},{"type":"string"}],"maxItems":4}',
                'spreadAfterAnOptionalEnd' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":["string","integer"]},{"type":["integer","string"]},{"type":"string"}],"minItems":2,"maxItems":4}',
                'mergeAfterStringKeys' => '{"type":"object","properties":{"id":{"type":"integer","const":1},"0":{"type":["integer","string"]},"1":{"type":"string"}},"required":["id"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function spreadingAListShapeKeepsTheEntriesEachIndexCanHold(): void
    {
        $closures = [
            'once' => fn () => [...NonListArrayTest::listWithOptionalEntries()],
            'twice' => fn () => [...NonListArrayTest::listWithOptionalEntries(), ...NonListArrayTest::listWithOptionalEntries()],
        ];

        $this->assertSame(
            [
                'once' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"},{"type":"boolean"}],"minItems":1,"maxItems":3}',
                'twice' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":["string","integer"]},{"type":["boolean","integer","string"]},{"type":["integer","string","boolean"]},{"type":["string","boolean"]},{"type":"boolean"}],"minItems":2,"maxItems":6}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function spreadingAnArrayOfUnknownSizeKeepsItsItemsAndKeys(): void
    {
        $closures = [
            'listAfterTuple' => fn () => [...NonListArrayTest::tuple(), ...NonListArrayTest::ints()],
            'tupleAfterList' => fn () => [...NonListArrayTest::intItems(), ...NonListArrayTest::tuple()],
            'listAfterStringKey' => fn (int $id) => ['id' => $id, ...NonListArrayTest::ints()],
            'stringKeyedMap' => fn () => [...NonListArrayTest::scores()],
        ];

        $this->assertSame(
            [
                'listAfterTuple' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"},{"type":"boolean"}],"items":{"type":"integer"},"minItems":3}',
                'tupleAfterList' => '{"type":"array","items":{"type":["integer","string","boolean"]}}',
                'listAfterStringKey' => '{"type":"object","properties":{"id":{"type":"integer"}},"required":["id"],"additionalProperties":{"type":"integer"}}',
                'stringKeyedMap' => '{"type":"object","additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayMergeTreatsAListWithoutAKeyTypeAsIntKeyed(): void
    {
        $closure = fn () => array_merge(NonListArrayTest::intItems(), NonListArrayTest::tuple());

        $this->assertSame('{"type":"array","items":{"type":["integer","string","boolean"]}}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function mappingAShapeKeepsWhetherItsIntKeysAreAList(): void
    {
        $closures = [
            'list' => fn (string $first, string $second) => array_map(fn (string $value) => $value, [$first, $second]),
            'notAList' => fn (string $first, string $second) => array_map(fn (string $value) => $value, [1 => $first, 2 => $second]),
        ];

        $this->assertSame(
            [
                'list' => self::STRING_LIST,
                'notAList' => '{"type":"object","additionalProperties":{"type":"string"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function declaredIntKeyedArraysStayLists(): void
    {
        $closure = fn () => [
            'list' => NonListArrayTest::ints(),
            'intKeyed' => NonListArrayTest::intKeyedInts(),
            'items' => NonListArrayTest::intItems(),
        ];

        $this->assertSame(
            '{"type":"object","properties":{"list":' . self::INTEGER_LIST . ',"intKeyed":' . self::INTEGER_LIST . ',"items":' . self::INTEGER_LIST . '},"required":["list","intKeyed","items"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aBranchThatFiltersTheListMakesTheResultMaybeAMap(): void
    {
        $closure = fn (bool $onlyLarge) => $onlyLarge
            ? array_filter(NonListArrayTest::ints(), fn (int $value) => $value > 10)
            : NonListArrayTest::ints();

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function keyPreservingFunctionsMayLeaveGaps(): void
    {
        $closures = [
            'unique' => fn () => array_unique(NonListArrayTest::strings()),
            'diff' => fn () => array_diff(NonListArrayTest::strings(), ['a']),
            'intersect' => fn () => array_intersect(NonListArrayTest::strings(), NonListArrayTest::strings()),
            'reverse' => fn () => array_reverse(NonListArrayTest::strings(), true),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::STRING_LIST_OR_MAP),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function renumberingVariantsOfKeyPreservingFunctionsKeepTheList(): void
    {
        $closures = [
            'reverse' => fn () => array_reverse(NonListArrayTest::ints()),
            'slice' => fn () => array_slice(NonListArrayTest::ints(), 1),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::INTEGER_LIST),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function slicingThatMayPreserveKeysMayLeaveGaps(): void
    {
        $closures = [
            'preserved' => fn () => array_slice(NonListArrayTest::ints(), 1, preserve_keys: true),
            'unknownFlag' => fn (bool $preserve) => array_slice(NonListArrayTest::ints(), 0, 2, $preserve),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::INTEGER_LIST_OR_MAP),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayDiffKeyRemovesTheLiteralKeys(): void
    {
        $closure = fn (string $name, string $password) => array_diff_key(['name' => $name, 'password' => $password], ['password' => true]);

        $this->assertSame('{"type":"object","properties":{"name":{"type":"string"}},"required":["name"]}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function arrayIntersectKeyKeepsOnlyTheLiteralKeys(): void
    {
        $closure = fn (string $name, string $password) => array_intersect_key(['name' => $name, 'password' => $password], ['name' => true]);

        $this->assertSame('{"type":"object","properties":{"name":{"type":"string"}},"required":["name"]}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function valueComparingFunctionsMakeShapeEntriesOptional(): void
    {
        $closure = fn (string $name, string $email) => array_unique(['name' => $name, 'email' => $email]);

        $this->assertSame(
            '{"type":"object","properties":{"name":{"type":"string"},"email":{"type":"string"}},"required":["name"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function arrayFlipOfIntValuesGivesIntKeysThatMayBeAMap(): void
    {
        $closure = fn () => array_flip(NonListArrayTest::ints());

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function arrayFlipMakesTheIndexesOfAListItsValues(): void
    {
        $closures = [
            'array_keys' => fn () => array_flip(array_keys(NonListArrayTest::scores())),
            'array_values' => fn () => array_flip(array_values(NonListArrayTest::scores())),
            'sort' => function () {
                $strings = NonListArrayTest::strings();
                sort($strings);

                return array_flip($strings);
            },
            'declared item type' => fn () => array_flip(NonListArrayTest::intItems()),
        ];

        $this->assertSame(
            [
                'array_keys' => '{"type":"object","additionalProperties":{"type":"integer"}}',
                'array_values' => self::INTEGER_LIST_OR_MAP,
                'sort' => '{"type":"object","additionalProperties":{"type":"integer"}}',
                'declared item type' => self::INTEGER_LIST_OR_MAP,
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function keyPreservingSortsMayReorderAList(): void
    {
        $closures = [
            'asort' => function () {
                $ints = NonListArrayTest::ints();
                asort($ints);

                return $ints;
            },
            'arsort' => function () {
                $ints = NonListArrayTest::ints();
                arsort($ints);

                return $ints;
            },
            'krsort' => function () {
                $ints = NonListArrayTest::ints();
                krsort($ints);

                return $ints;
            },
            'uasort' => function () {
                $ints = NonListArrayTest::ints();
                uasort($ints, fn (int $a, int $b) => $a <=> $b);

                return $ints;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::INTEGER_LIST_OR_MAP),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function keyPreservingSortsOfATupleMayReorderItsEntries(): void
    {
        $closure = function (int $first, int $second) {
            $pair = [$first, $second];
            asort($pair);

            return $pair;
        };

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function sortingAStringKeyedShapeKeepsTheShape(): void
    {
        $closure = function (int $width, int $height) {
            $size = ['width' => $width, 'height' => $height];
            asort($size);

            return $size;
        };

        $this->assertSame(
            '{"type":"object","properties":{"width":{"type":"integer"},"height":{"type":"integer"}},"required":["width","height"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function sortingAShapeThatIsNeverAListKeepsTheShape(): void
    {
        $closures = [
            'gapInIntKeys' => function (string $first, string $second) {
                $map = [5 => $first, 9 => $second];
                asort($map);

                return $map;
            },
            'intAndStringKeys' => function (string $first, string $second) {
                $map = [0 => $first, 'name' => $second];
                asort($map);

                return $map;
            },
        ];

        $this->assertSame(
            [
                'gapInIntKeys' => '{"type":"object","properties":{"5":{"type":"string"},"9":{"type":"string"}},"required":["5","9"]}',
                'intAndStringKeys' => '{"type":"object","properties":{"0":{"type":"string"},"name":{"type":"string"}},"required":["0","name"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function renumberingSortsMakeAList(): void
    {
        $closure = function () {
            $scores = NonListArrayTest::scores();
            usort($scores, fn (int $a, int $b) => $a <=> $b);

            return $scores;
        };

        $this->assertSame(self::INTEGER_LIST, $this->closureReturnSchema($closure));
    }


    #[Test]
    public function unsettingAListEntryMayLeaveAGap(): void
    {
        $closures = [
            'dynamicIndex' => function (int $index) {
                $ints = NonListArrayTest::ints();
                unset($ints[$index]);

                return $ints;
            },
            'firstIndex' => function () {
                $ints = NonListArrayTest::ints();
                unset($ints[0]);

                return $ints;
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), self::INTEGER_LIST_OR_MAP),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function unsettingAShapeKeyRemovesIt(): void
    {
        $closure = function (string $theme, string $locale) {
            $settings = ['theme' => $theme, 'locale' => $locale];
            unset($settings['locale']);

            return $settings;
        };

        $this->assertSame('{"type":"object","properties":{"theme":{"type":"string"}},"required":["theme"]}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function unsettingAShapeKeyInABranchMakesItOptional(): void
    {
        $closure = function (string $theme, string $locale, bool $useDefaultLocale) {
            $settings = ['theme' => $theme, 'locale' => $locale];

            if ($useDefaultLocale) {
                unset($settings['locale']);
            }

            return $settings;
        };

        $this->assertSame(
            '{"type":"object","properties":{"theme":{"type":"string"},"locale":{"type":"string"}},"required":["theme"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function unsettingANestedShapeKeyRemovesIt(): void
    {
        $closure = function (string $name, string $email) {
            $data = ['user' => ['name' => $name, 'email' => $email]];
            unset($data['user']['email']);

            return $data;
        };

        $this->assertSame(
            '{"type":"object","properties":{"user":{"type":"object","properties":{"name":{"type":"string"}},"required":["name"]}},"required":["user"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function unsettingAShapeKeyInAnObjectPropertyRemovesIt(): void
    {
        $closure = function (string $theme, string $locale) {
            $preferences = new stdClass;
            $preferences->settings = ['theme' => $theme, 'locale' => $locale];
            unset($preferences->settings['locale']);

            return $preferences;
        };

        $this->assertSame(
            '{"type":"object","properties":{"settings":{"type":"object","properties":{"theme":{"type":"string"}},"required":["theme"]}},"required":["settings"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function unsettingADynamicShapeKeyMakesEveryKeyOptional(): void
    {
        $closure = function (string $theme, string $locale, string $key) {
            $settings = ['theme' => $theme, 'locale' => $locale];
            unset($settings[$key]);

            return $settings;
        };

        $this->assertSame('{"type":"object","properties":{"theme":{"type":"string"},"locale":{"type":"string"}}}', $this->closureReturnSchema($closure));
    }


    #[Test]
    public function unsettingAKeyAndAssigningItAgainKeepsIt(): void
    {
        $closure = function (string $theme, string $locale) {
            $settings = ['theme' => $theme, 'locale' => $locale];
            unset($settings['locale']);
            $settings['locale'] = 'en';

            return $settings;
        };

        $this->assertSame(
            '{"type":"object","properties":{"theme":{"type":"string"},"locale":{"type":"string","const":"en"}},"required":["theme","locale"]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function unsettingATupleEntryKeepsTheOtherKeys(): void
    {
        $closures = [
            'last' => function () {
                $tuple = NonListArrayTest::tuple();
                unset($tuple[2]);

                return $tuple;
            },
            'first' => function () {
                $tuple = NonListArrayTest::tuple();
                unset($tuple[0]);

                return $tuple;
            },
            'middleInABranch' => function (bool $withoutName) {
                $tuple = NonListArrayTest::tuple();

                if ($withoutName) {
                    unset($tuple[1]);
                }

                return $tuple;
            },
        ];

        $this->assertSame(
            [
                'last' => '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2}',
                'first' => '{"type":"object","properties":{"1":{"type":"string"},"2":{"type":"boolean"}},"required":["1","2"]}',
                'middleInABranch' => '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"},{"type":"boolean"}],"minItems":3,"maxItems":3},{"type":"object","properties":{"0":{"type":"integer"},"2":{"type":"boolean"}},"required":["0","2"]}]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function keyPreservingFunctionsKeepTheKeysOfATuple(): void
    {
        $closures = [
            'reverse' => fn () => array_reverse(NonListArrayTest::tuple(), true),
            'diffKey' => fn () => array_diff_key(NonListArrayTest::tuple(), [0 => true]),
        ];

        $this->assertSame(
            [
                'reverse' => '{"type":"object","properties":{"2":{"type":"boolean"},"1":{"type":"string"},"0":{"type":"integer"}},"required":["2","1","0"]}',
                'diffKey' => '{"type":"object","properties":{"1":{"type":"string"},"2":{"type":"boolean"}},"required":["1","2"]}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function unsettingAStringKeyInABranchMayLeaveAList(): void
    {
        $closure = function (int $id, string $label, bool $withoutLabel) {
            $entry = [0 => $id, 'label' => $label];

            if ($withoutLabel) {
                unset($entry['label']);
            }

            return $entry;
        };

        $this->assertSame(
            '{"anyOf":[{"type":"object","properties":{"0":{"type":"integer"},"label":{"type":"string"}},"required":["0","label"]},{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1,"maxItems":1}]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function removingEveryEntryOfAShapeLeavesTheEmptyArray(): void
    {
        $closures = [
            'unset' => function () {
                $single = NonListArrayTest::single();
                unset($single[0]);

                return $single;
            },
            /** @phpstan-ignore arrayFilter.alwaysEmpty */
            'filter' => fn () => array_filter(NonListArrayTest::falsyPair()),
            /** @phpstan-ignore function.impossibleType */
            'filterWithCallback' => fn () => array_filter(NonListArrayTest::single(), fn (int $value) => is_string($value)),
            'diffKey' => fn () => array_diff_key(NonListArrayTest::optionalSingle(), [0 => true]),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), $this->closureReturnSchema(fn () => [])),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function filteringEntriesThatAreNeverFalsyKeepsAList(): void
    {
        /** @phpstan-ignore arrayFilter.same */
        $closure = fn () => array_filter(NonListArrayTest::objectsWithOptionalEnd());

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"object"},{"type":"object"},{"type":"object"}],"minItems":1,"maxItems":3}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aUnionOfShapesWithReorderedKeysKeepsBothEncodings(): void
    {
        $closure = fn (bool $reversed, int $id, string $name) => $reversed ? [1 => $name, 0 => $id] : [0 => $id, 1 => $name];

        $this->assertSame(
            '{"anyOf":[{"type":"object","properties":{"1":{"type":"string"},"0":{"type":"integer"}},"required":["1","0"]},{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2}]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aUnionOfShapesThatMayBothBeListsInDifferentKeyOrdersKeepsEachList(): void
    {
        $closure = fn (bool $reversed) => $reversed ? NonListArrayTest::reversedOptionalPair() : NonListArrayTest::optionalPair();

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"}],"maxItems":1},{"type":"object","properties":{"1":{"type":"string"},"0":{"type":"integer"}}},{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"maxItems":2},{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}}}]}',
            $this->closureReturnSchema($closure),
        );
    }


    #[Test]
    public function aUnionOfShapesWithReorderedNonListKeysMergesIntoOneObject(): void
    {
        $closure = fn (bool $reversed, int $id, string $name) => $reversed ? [2 => $name, 1 => $id] : [1 => $id, 2 => $name];

        $this->assertSame(
            '{"type":"object","properties":{"2":{"type":"string"},"1":{"type":"integer"}},"required":["2","1"]}',
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
     * @return list<int>
     */
    private static function ints(): array
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
     * @return list<array{id: int, email: string}>
     */
    private static function users(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<list<int>>
     */
    private static function intLists(): array
    {
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
     * @return array<int, int>
     */
    private static function intKeyedInts(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return int[]
     */
    private static function intItems(): array
    {
        return json_decode('[]', true);
    }


    /** @phpstan-ignore missingType.iterableValue */
    private static function untyped(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{code: string, displayName?: string|null}[]
     */
    private static function services(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array<array{code: string, displayName?: string|null}>
     */
    private static function serviceArray(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<array{code: string, displayName?: string|null}>
     */
    private static function serviceList(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<string>
     */
    private static function strings(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return list<object>
     */
    private static function objects(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array<string, int>
     */
    private static function scores(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{int, string, bool}
     */
    private static function tuple(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array{int, ?string, bool}
     */
    private static function nullableTuple(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array{int, ?string}
     */
    private static function nullablePair(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return list{0: int, 1?: string}
     */
    private static function listWithOptionalEnd(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return list{0: int, 1?: string, 2?: bool}
     */
    private static function listWithOptionalEntries(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return list{0: object, 1?: object, 2?: object}
     */
    private static function objectsWithOptionalEnd(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array{null, false}
     */
    private static function falsyPair(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array{0: int}
     */
    private static function single(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }


    /**
     * @return array{0?: int}
     */
    private static function optionalSingle(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{0?: int, 1?: string}
     */
    private static function optionalPair(): array
    {
        return json_decode('[]', true);
    }


    /**
     * @return array{1?: string, 0?: int}
     */
    private static function reversedOptionalPair(): array
    {
        return json_decode('[]', true);
    }
}
