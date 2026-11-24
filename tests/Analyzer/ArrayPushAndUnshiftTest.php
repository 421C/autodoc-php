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

final class ArrayPushAndUnshiftTest extends TestCase
{
    use LoadsConfig;

    private const array OPERATIONS = [
        'close acts' => 'Close acts',
        'export data' => 'Export data',
    ];

    #[Test]
    public function arrayPushAddsItsValuesToTheArray(): void
    {
        $closures = [
            'oneValue' => function () {
                $names = [];
                array_push($names, 'a');

                return $names;
            },
            'severalValues' => function () {
                $names = [];
                array_push($names, 'a', 'b');

                return $names;
            },
            'spreadValues' => function () {
                $names = [];
                array_push($names, ...['a', 'b']);

                return $names;
            },
            'spreadList' => function () {
                $names = ['a'];
                array_push($names, ...ArrayPushAndUnshiftTest::strings());

                return $names;
            },
            'valueAndSpread' => function () {
                $names = [];
                array_push($names, 'a', ...ArrayPushAndUnshiftTest::ints());

                return $names;
            },
        ];

        $this->assertSame(
            [
                'oneValue' => '{"type":"array","items":{"type":"string","const":"a"}}',
                'severalValues' => '{"type":"array","items":{"type":"string","enum":["a","b"]}}',
                'spreadValues' => '{"type":"array","items":{"type":"string","enum":["a","b"]}}',
                'spreadList' => '{"type":"array","items":{"type":"string"}}',
                'valueAndSpread' => '{"type":"array","items":{"anyOf":[{"type":"string","const":"a"},{"type":"integer"}]}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayPushAppendsEachValueLikeAnAppendAssignment(): void
    {
        $pushes = [
            'noValues' => function () {
                $names = [];
                array_push($names);

                return $names;
            },
            'empty' => function () {
                $names = [];
                array_push($names, 'a');

                return $names;
            },
            'severalValues' => function () {
                $names = [];
                array_push($names, 'a', 'b');

                return $names;
            },
            'list' => function () {
                $names = ArrayPushAndUnshiftTest::strings();
                array_push($names, 'a');

                return $names;
            },
            'literalList' => function () {
                $names = ['b'];
                array_push($names, 'a');

                return $names;
            },
            'stringKeyedShape' => function () {
                $user = ['id' => 1];
                array_push($user, 'a');

                return $user;
            },
            'intKeyedMap' => function () {
                $names = [5 => 'x'];
                array_push($names, 'a');

                return $names;
            },
            'repeatedPushes' => function () {
                $names = [];
                array_push($names, 'a');
                array_push($names, 1);

                return $names;
            },
            'nestedKey' => function () {
                $data = ['names' => [], 'count' => 0];
                array_push($data['names'], 'a');

                return $data;
            },
            'property' => function () {
                $holder = new stdClass;
                $holder->names = [];
                array_push($holder->names, 'a');

                return $holder;
            },
            'branch' => function (bool $flag) {
                $names = [];

                if ($flag) {
                    array_push($names, 'a');
                }

                return $names;
            },
            'loop' => function () {
                $names = [];

                foreach (ArrayPushAndUnshiftTest::strings() as $name) {
                    array_push($names, $name);
                }

                return $names;
            },
        ];

        $appends = [
            'noValues' => function () {
                $names = [];

                return $names;
            },
            'empty' => function () {
                $names = [];
                $names[] = 'a';

                return $names;
            },
            'severalValues' => function () {
                $names = [];
                $names[] = 'a';
                $names[] = 'b';

                return $names;
            },
            'list' => function () {
                $names = ArrayPushAndUnshiftTest::strings();
                $names[] = 'a';

                return $names;
            },
            'literalList' => function () {
                $names = ['b'];
                $names[] = 'a';

                return $names;
            },
            'stringKeyedShape' => function () {
                $user = ['id' => 1];
                $user[] = 'a';

                return $user;
            },
            'intKeyedMap' => function () {
                $names = [5 => 'x'];
                $names[] = 'a';

                return $names;
            },
            'repeatedPushes' => function () {
                $names = [];
                $names[] = 'a';
                $names[] = 1;

                return $names;
            },
            'nestedKey' => function () {
                $data = ['names' => [], 'count' => 0];
                $data['names'][] = 'a';

                return $data;
            },
            'property' => function () {
                $holder = new stdClass;
                $holder->names = [];
                $holder->names[] = 'a';

                return $holder;
            },
            'branch' => function (bool $flag) {
                $names = [];

                if ($flag) {
                    $names[] = 'a';
                }

                return $names;
            },
            'loop' => function () {
                $names = [];

                foreach (ArrayPushAndUnshiftTest::strings() as $name) {
                    $names[] = $name;
                }

                return $names;
            },
        ];

        $this->assertSame(
            array_map($this->closureReturnSchema(...), $appends),
            array_map($this->closureReturnSchema(...), $pushes),
        );
    }


    #[Test]
    public function arrayPushOfASpreadAppendsEachEntryOfIt(): void
    {
        $pushes = [
            'list' => function () {
                $names = [];
                array_push($names, ...ArrayPushAndUnshiftTest::strings());

                return $names;
            },
            'literal' => function () {
                $names = ['x'];
                array_push($names, ...['a', 'b']);

                return $names;
            },
        ];

        $appends = [
            'list' => function () {
                $names = [];

                foreach (ArrayPushAndUnshiftTest::strings() as $name) {
                    $names[] = $name;
                }

                return $names;
            },
            'literal' => function () {
                $names = ['x'];

                foreach (['a', 'b'] as $name) {
                    $names[] = $name;
                }

                return $names;
            },
        ];

        $this->assertSame(
            array_map($this->closureReturnSchema(...), $appends),
            array_map($this->closureReturnSchema(...), $pushes),
        );
    }


    #[Test]
    public function arrayUnshiftPutsItsValuesBeforeTheEntries(): void
    {
        $closures = [
            'empty' => function () {
                $names = [];
                array_unshift($names, 'a');

                return $names;
            },
            'list' => function () {
                $names = ['b'];
                array_unshift($names, 'a');

                return $names;
            },
            'spreadList' => function () {
                $names = ['a'];
                array_unshift($names, ...ArrayPushAndUnshiftTest::strings());

                return $names;
            },
        ];

        $this->assertSame(
            [
                'empty' => '{"type":"array","items":{"type":"string","const":"a"}}',
                'list' => '{"type":"array","items":{"type":"string","enum":["b","a"]}}',
                'spreadList' => '{"type":"array","items":{"type":"string"}}',
            ],
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function arrayUnshiftRenumbersLikeASpreadAfterItsValues(): void
    {
        $unshifts = [
            'noValues' => function () {
                $names = [5 => 'x', 'k' => 'y'];
                array_unshift($names);

                return $names;
            },
            'empty' => function () {
                $names = [];
                array_unshift($names, 'a');

                return $names;
            },
            'severalValues' => function () {
                $names = ['c'];
                array_unshift($names, 'a', 'b');

                return $names;
            },
            'list' => function () {
                $names = ArrayPushAndUnshiftTest::strings();
                array_unshift($names, 1);

                return $names;
            },
            'stringKeyedShape' => function () {
                $user = ['id' => 1];
                array_unshift($user, 'a');

                return $user;
            },
            'intAndStringKeys' => function () {
                $names = [5 => 'x', 'k' => 'y'];
                array_unshift($names, 'z');

                return $names;
            },
            'spreadValues' => function () {
                $names = ['c'];
                array_unshift($names, 'a', ...ArrayPushAndUnshiftTest::ints());

                return $names;
            },
            'nestedKey' => function () {
                $data = ['names' => ['b'], 'count' => 0];
                array_unshift($data['names'], 'a');

                return $data;
            },
            'property' => function () {
                $holder = new stdClass;
                $holder->names = ['b'];
                array_unshift($holder->names, 'a');

                return $holder;
            },
            'branch' => function (bool $flag) {
                $names = [];

                if ($flag) {
                    array_unshift($names, 'a');
                }

                return $names;
            },
            'loop' => function () {
                $names = [];

                foreach (ArrayPushAndUnshiftTest::strings() as $name) {
                    array_unshift($names, $name);
                }

                return $names;
            },
        ];

        $spreads = [
            'noValues' => function () {
                $names = [5 => 'x', 'k' => 'y'];
                $names = [...$names];

                return $names;
            },
            'empty' => function () {
                $names = [];
                $names = ['a', ...$names];

                return $names;
            },
            'severalValues' => function () {
                $names = ['c'];
                $names = ['a', 'b', ...$names];

                return $names;
            },
            'list' => function () {
                $names = ArrayPushAndUnshiftTest::strings();
                $names = [1, ...$names];

                return $names;
            },
            'stringKeyedShape' => function () {
                $user = ['id' => 1];
                $user = ['a', ...$user];

                return $user;
            },
            'intAndStringKeys' => function () {
                $names = [5 => 'x', 'k' => 'y'];
                $names = ['z', ...$names];

                return $names;
            },
            'spreadValues' => function () {
                $names = ['c'];
                $names = ['a', ...ArrayPushAndUnshiftTest::ints(), ...$names];

                return $names;
            },
            'nestedKey' => function () {
                $data = ['names' => ['b'], 'count' => 0];
                $data['names'] = ['a', ...$data['names']];

                return $data;
            },
            'property' => function () {
                $holder = new stdClass;
                $holder->names = ['b'];
                $holder->names = ['a', ...$holder->names];

                return $holder;
            },
            'branch' => function (bool $flag) {
                $names = [];

                if ($flag) {
                    $names = ['a', ...$names];
                }

                return $names;
            },
            'loop' => function () {
                $names = [];

                foreach (ArrayPushAndUnshiftTest::strings() as $name) {
                    $names = [$name, ...$names];
                }

                return $names;
            },
        ];

        $this->assertSame(
            array_map($this->closureReturnSchema(...), $spreads),
            array_map($this->closureReturnSchema(...), $unshifts),
        );
    }


    #[Test]
    public function arrayPushAndArrayUnshiftReturnTheNewCount(): void
    {
        $closures = [
            'array_push' => function () {
                $names = [];

                return array_push($names, 'a');
            },
            'array_unshift' => function () {
                $names = [];

                return array_unshift($names, 'a');
            },
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"integer"}'),
            array_map($this->closureReturnSchema(...), $closures),
        );
    }


    #[Test]
    public function valuesAddedInALoopKeepTheDocumentedItemTypeUnderDeepShapeInference(): void
    {
        $closures = [
            'array_push' => fn () => ArrayPushAndUnshiftTest::permissionNamesPushedInALoop(),
            'array_unshift' => fn () => ArrayPushAndUnshiftTest::permissionNamesUnshiftedInALoop(),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($closures), '{"type":"array","items":{"type":"string"}}'),
            array_map(fn (Closure $closure) => $this->closureReturnSchema($closure, deepShapeInference: true), $closures),
        );
    }


    private function closureReturnSchema(Closure $closure, bool $deepShapeInference = false): string
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;
        $config->data['arrays']['deep_shape_inference'] = $deepShapeInference;

        $type = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return json_encode(SchemaJson::castPropertiesToObjects($type->toSchema($config)), JSON_THROW_ON_ERROR);
    }


    /**
     * @return list<string>
     */
    private static function permissionNamesPushedInALoop(): array
    {
        $names = [];

        foreach (self::resources() as $resource) {
            array_push($names, ...array_values($resource['permissions']));
        }

        return [...$names, ...array_keys(self::OPERATIONS)];
    }


    /**
     * @return list<string>
     */
    private static function permissionNamesUnshiftedInALoop(): array
    {
        $names = [];

        foreach (self::resources() as $resource) {
            array_unshift($names, ...array_values($resource['permissions']));
        }

        return [...$names, ...array_keys(self::OPERATIONS)];
    }


    /**
     * @return list<array{permissions: array<string, string>}>
     */
    private static function resources(): array
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
     * @return list<int>
     */
    private static function ints(): array
    {
        return json_decode('[]', true);
    }
}
