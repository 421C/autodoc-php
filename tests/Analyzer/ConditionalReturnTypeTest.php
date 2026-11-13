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

    #[Test]
    public function literalArrayArgumentMatchingTheShapePicksTheIfBranch(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => [
            'flat' => ConditionalReturnTypeTest::describeShape(['a' => 1, 'b' => 2]),
            'nested' => ConditionalReturnTypeTest::describeNestedShape(['a' => ['x' => 1]]),
        ]);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'flat' => ['type' => 'integer'],
                'nested' => ['type' => 'integer'],
            ],
            'required' => ['flat', 'nested'],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function unionArgumentWhoseMembersAllMatchPicksTheIfBranch(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => [
            'scalar' => ConditionalReturnTypeTest::describeKey(rand(0, 1) ? 1 : 'a'),
            'shapeEntry' => ConditionalReturnTypeTest::describeKeyedShape(['key' => rand(0, 1) ? 1 : 'a']),
        ]);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'scalar' => ['type' => 'integer'],
                'shapeEntry' => ['type' => 'integer'],
            ],
            'required' => ['scalar', 'shapeEntry'],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function bindingATemplateFromTheArgumentKeepsItsShapeForTheCondition(): void
    {
        $schema = $this->getClosureReturnSchema(fn (): mixed => ConditionalReturnTypeTest::describeTemplatedShape(['a' => 1, 'b' => 2]));

        $this->assertSchemaArraysMatch(['type' => 'integer'], $schema, 'closure', 'return');
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
     * @return ($key is int|string ? int : string)
     */
    private static function describeKey(int|string|null $key): int|string
    {
        return $key ?? 'none';
    }

    /**
     * @param array<string, int|string> $items
     *
     * @return ($items is array{key: int|string} ? int : string)
     */
    private static function describeKeyedShape(array $items): int|string
    {
        return $items === [] ? 'empty' : count($items);
    }

    /**
     * @param array<string, int> $items
     *
     * @return ($items is array{a: int, b: int} ? int : string)
     */
    private static function describeShape(array $items): int|string
    {
        return $items === [] ? 'empty' : count($items);
    }

    /**
     * @param array<string, array<string, int>> $items
     *
     * @return ($items is array{a: array{x: int}} ? int : string)
     */
    private static function describeNestedShape(array $items): int|string
    {
        return $items === [] ? 'empty' : count($items);
    }

    /**
     * @template TValue
     *
     * @param array<string, TValue> $items
     *
     * @return ($items is array{a: int, b: int} ? int : string)
     */
    private static function describeTemplatedShape(array $items): int|string
    {
        return $items === [] ? 'empty' : count($items);
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
