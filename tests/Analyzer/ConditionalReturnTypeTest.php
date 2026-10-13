<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class ConditionalReturnTypeTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function omittedArgumentUsesTheParameterDefault(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::respond());

        $this->assertSchemaArraysMatch(['type' => 'integer'], $schema, 'closure', 'return');
    }

    #[Test]
    public function literalNullArgumentPicksTheIfBranch(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::respond(null));

        $this->assertSchemaArraysMatch(['type' => 'integer'], $schema, 'closure', 'return');
    }

    #[Test]
    public function nonNullLiteralArgumentPicksTheElseBranch(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::respond('x'));

        $this->assertSchemaArraysMatch(['type' => 'string'], $schema, 'closure', 'return');
    }

    #[Test]
    public function namedArgumentIsMatchedToItsParameter(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::respond(status: 201, content: 'x'));

        $this->assertSchemaArraysMatch(['type' => 'string'], $schema, 'closure', 'return');
    }

    #[Test]
    public function namedArgumentForAnotherParameterLeavesTheConditionOnTheDefault(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::respond(status: 201));

        $this->assertSchemaArraysMatch(['type' => 'integer'], $schema, 'closure', 'return');
    }

    #[Test]
    public function undecidableArgumentKeepsBothBranches(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $content = rand(0, 1) ? null : 'x';

            return ConditionalReturnTypeTest::respond($content);
        });

        $this->assertSchemaArraysMatch(['type' => ['integer', 'string']], $schema, 'closure', 'return');
    }

    #[Test]
    public function negatedConditionSwapsTheBranches(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => [
            'omitted' => ConditionalReturnTypeTest::respondUnlessNull(),
            'nonNull' => ConditionalReturnTypeTest::respondUnlessNull(1),
        ]);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'omitted' => ['type' => 'integer'],
                'nonNull' => ['type' => 'string'],
            ],
            'required' => ['omitted', 'nonNull'],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function conditionInsideAShapeIsDecidedToo(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::respondWrapped('x'));

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'string'],
            ],
            'required' => ['data'],
        ], $schema, 'closure', 'return');
    }

    /**
     * Mirrors Laravel's `response()` helper.
     *
     * @return ($content is null ? int : string)
     */
    private static function respond(?string $content = null, int $status = 200): int|string
    {
        return $content ?? $status;
    }

    /**
     * @return ($content is not null ? string : int)
     */
    private static function respondUnlessNull(?int $content = null): int|string
    {
        return $content === null ? 0 : (string) $content;
    }

    /**
     * @return array{data: ($content is null ? int : string)}
     */
    private static function respondWrapped(?string $content = null): array
    {
        return ['data' => $content ?? 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure): array
    {
        $config = self::loadConfig();
        $scope = new Scope($config);

        $type = new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->getReturnType(usePhpDocIfAvailable: false);

        return $type->toSchema($config);
    }
}
