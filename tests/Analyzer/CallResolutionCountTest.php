<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\CallCountHolder;
use AutoDoc\Tests\TestProject\Extensions\FuncCallCountingExtension;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use RuntimeException;

/**
 * Breakout (never-return) detection peeks at the return type of call
 * statements. The body-entry scan, every enclosing condition's exit check,
 * and variable event replays all visit the same statements, so without
 * caching the same callee is re-analyzed combinatorially — deep enough
 * call chains exhausted memory on real projects. These tests pin the
 * resolution counts.
 */
final class CallResolutionCountTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function aCallStatementIsResolvedOnceDespiteRepeatedBreakoutScans(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $x): string {
            \AutoDoc\Tests\Analyzer\callResolutionCountMarker();

            if ($x === 1) {
                \AutoDoc\Tests\Analyzer\callResolutionCountMarker();

                return 'a';

            } elseif ($x === 2) {
                \AutoDoc\Tests\Analyzer\callResolutionCountMarker();

                return 'b';
            }

            return 'c';
        });

        $this->assertSchemaArraysMatch([
            'enum' => ['a', 'b', 'c'],
            'type' => 'string',
        ], $schema, 'closure', 'return');

        $this->assertSame(1, $this->resolutionCount('callResolutionCountMarker'));
    }

    #[Test]
    public function repeatedNeverGuardsResolveTheGuardFunctionOnceAndStillNarrow(): void
    {
        $schema = $this->getClosureReturnSchema(function (int|string|null $x): mixed {
            if ($x === null) {
                \AutoDoc\Tests\Analyzer\callResolutionCountAbort();
            }

            if (is_string($x)) {
                \AutoDoc\Tests\Analyzer\callResolutionCountAbort();
            }

            return $x;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'integer',
        ], $schema, 'closure', 'return');

        $this->assertSame(1, $this->resolutionCount('callResolutionCountAbort'));
    }

    // Each read position replays the events once; sharing replays across
    // positions would require comparing the visible event sets first.
    #[Test]
    public function literalKeyReadsOfAnUnmutatedVariableReplayItsEventsOncePerReadPosition(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $items = \AutoDoc\Tests\Analyzer\callResolutionCountShape();

            return ['first' => $items['a'], 'second' => $items['b']];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'first' => [
                    'type' => 'integer',
                    'const' => 1,
                ],
                'second' => [
                    'type' => 'string',
                    'const' => 'b',
                ],
            ],
            'required' => [
                'first',
                'second',
            ],
        ], $schema, 'closure', 'return');

        $this->assertSame(2, $this->resolutionCount('callResolutionCountShape'));
    }

    #[Test]
    public function aFailedExactChainReadDoesNotReplayTheRootVariableTwice(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $holder = \AutoDoc\Tests\Analyzer\callResolutionCountHolder();

            // @phpstan-ignore property.notFound, offsetAccess.nonOffsetAccessible
            return ['value' => $holder->undeclared['x']];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'value',
            ],
        ], $schema, 'closure', 'return');

        $this->assertSame(1, $this->resolutionCount('callResolutionCountHolder'));
    }

    // Each level re-analyzed the body down to `max_depth`. When the merged list
    // also joins the return union, as in Laravel's `data_get()`, every merge
    // resolves the lazy item again and the analysis takes minutes.
    #[Test]
    public function aRecursiveCallWhoseResultsAreMergedElsewhereIsNotAnalyzedAgain(): void
    {
        $this->getClosureReturnSchema(fn () => [
            'id' => \AutoDoc\Tests\Analyzer\callResolutionCountRecursiveRead(['id' => 1], 'id'),
        ]);

        $this->assertSame(2, $this->resolutionCount('callResolutionCountRecursiveRead'));
    }

    #[Test]
    public function aRecursiveFunctionCallResolvesToTheDeclaredReturnTypeInsteadOfNestingItsBody(): void
    {
        $schema = $this->getClosureReturnSchema(fn () => \AutoDoc\Tests\Analyzer\callResolutionCountNested(3));

        $this->assertSchemaArraysMatch(self::NESTED_ONCE_SCHEMA, $schema, 'closure', 'return');
    }

    #[Test]
    public function aRecursiveMethodCallResolvesToTheDeclaredReturnTypeInsteadOfNestingItsBody(): void
    {
        $schema = $this->getClosureReturnSchema(fn () => CallResolutionCountTest::nestedByMethod(3));

        $this->assertSchemaArraysMatch(self::NESTED_ONCE_SCHEMA, $schema, 'closure', 'return');
    }

    private const array NESTED_ONCE_SCHEMA = [
        'type' => ['object', 'null'],
        'properties' => [
            'child' => [
                'type' => ['array', 'null'],
                'items' => [
                    'type' => 'string',
                ],
            ],
        ],
        'required' => [
            'child',
        ],
    ];

    // @phpstan-ignore missingType.iterableValue
    private static function nestedByMethod(int $levels): ?array
    {
        if ($levels === 0) {
            return null;
        }

        return ['child' => self::nestedByMethod($levels - 1)];
    }

    /**
     * Counts resolutions regardless of whether the analyzer saw the
     * qualified or unqualified function name.
     */
    private function resolutionCount(string $functionName): int
    {
        $count = 0;

        foreach (FuncCallCountingExtension::$callCounts as $calledName => $calls) {
            if ($calledName === $functionName || str_ends_with($calledName, '\\' . $functionName)) {
                $count += $calls;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure): array
    {
        FuncCallCountingExtension::reset();

        $config = self::loadConfig();
        $config->data['extensions'] = [FuncCallCountingExtension::class];
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $scope = new Scope($config);

        $type = new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->getReturnType(usePhpDocIfAvailable: false);

        return $type->toSchema($config);
    }
}

function callResolutionCountMarker(): void
{
}

function callResolutionCountAbort(): never
{
    throw new RuntimeException('abort');
}

/**
 * @return array{a: 1, b: 'b'}
 */
function callResolutionCountShape(): array
{
    return ['a' => 1, 'b' => 'b'];
}

function callResolutionCountHolder(): CallCountHolder
{
    return new CallCountHolder;
}

function callResolutionCountRecursiveRead(mixed $target, string $path): mixed
{
    foreach (explode('.', $path) as $segment) {
        if ($segment === '*') {
            if (! is_iterable($target)) {
                return null;
            }

            $result = [];

            foreach ($target as $item) {
                $result[] = callResolutionCountRecursiveRead($item, $path);
            }

            return callResolutionCountCollapse($result);
        }

        if (! is_array($target) || ! array_key_exists($segment, $target)) {
            return null;
        }

        $target = $target[$segment];
    }

    return $target;
}

function callResolutionCountCollapse(mixed $lists): mixed
{
    if (! is_iterable($lists)) {
        return [];
    }

    $results = [];

    foreach ($lists as $values) {
        if (is_array($values)) {
            $results[] = $values;
        }
    }

    return array_merge([], ...$results);
}

// @phpstan-ignore missingType.iterableValue
function callResolutionCountNested(int $levels): ?array
{
    if ($levels === 0) {
        return null;
    }

    return ['child' => callResolutionCountNested($levels - 1)];
}
