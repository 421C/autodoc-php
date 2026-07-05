<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
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
