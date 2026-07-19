<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use ErrorException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * JSON and OpenAPI numbers must be finite, so scalar types that pick up NAN/INF/-INF
 * from analyzed PHP must render and merge without a `const`/`enum` literal constraint
 * and without string-coercing those values to deduplicate them.
 */
final class NonFiniteScalarValuesTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function nonFiniteFloatRendersWithoutLiteralConstraint(): void
    {
        $config = self::loadConfig();

        foreach (['NAN' => NAN, 'INF' => INF, '-INF' => -INF] as $label => $value) {
            $schema = (new FloatType($value))->toSchema($config);

            self::assertSame('number', $schema['type'] ?? null, $label);
            self::assertSame('float', $schema['format'] ?? null, $label);
            self::assertArrayNotHasKey('const', $schema, $label);
            self::assertArrayNotHasKey('enum', $schema, $label);
        }
    }

    #[Test]
    public function mixedFiniteAndNonFiniteNumberOmitsTheEntireLiteralConstraint(): void
    {
        $config = self::loadConfig();

        $schema = (new NumberType([1.5, NAN, 2.5]))->toSchema($config);

        self::assertSame('number', $schema['type'] ?? null);
        self::assertArrayNotHasKey('const', $schema);
        self::assertArrayNotHasKey('enum', $schema);
    }

    #[Test]
    public function mergingANonFiniteUnionProducesAnUnconstrainedNumericSchema(): void
    {
        $config = self::loadConfig();

        $type = $this->withoutPhpErrors(
            fn () => (new UnionType([new FloatType(NAN), new FloatType(1.5)]))->unwrapType($config),
        );

        $schema = $type->toSchema($config);

        self::assertSame('number', $schema['type'] ?? null);
        self::assertArrayNotHasKey('const', $schema);
        self::assertArrayNotHasKey('enum', $schema);
    }

    #[Test]
    public function intersectingNonFiniteNumericValuesDoesNotCollapseToNever(): void
    {
        $config = self::loadConfig();

        $type = $this->withoutPhpErrors(
            fn () => (new IntersectionType([new FloatType(NAN), new FloatType(NAN)]))->unwrapType($config),
        );

        self::assertInstanceOf(FloatType::class, $type);

        $schema = $type->toSchema($config);

        self::assertSame('number', $schema['type'] ?? null);
        self::assertArrayNotHasKey('const', $schema);
        self::assertArrayNotHasKey('enum', $schema);
    }

    #[Test]
    public function finiteIntersectionSurvivesAlongsideANonFiniteValue(): void
    {
        $config = self::loadConfig();

        $type = (new IntersectionType([
            new NumberType([NAN, 1.5]),
            new NumberType(1.5),
        ]))->unwrapType($config);

        self::assertSame([
            'type' => 'number',
            'const' => 1.5,
        ], $type->toSchema($config));
    }

    #[Test]
    public function disjointNonFiniteAndFiniteValuesCollapseToNever(): void
    {
        $config = self::loadConfig();
        $config->data['intersections']['render_empty_as_unknown'] = false;

        $type = (new IntersectionType([
            new NumberType(NAN),
            new NumberType(1.5),
        ]))->unwrapType($config);

        self::assertInstanceOf(NeverType::class, $type);
        self::assertSame(['enum' => []], $type->toSchema($config));
    }

    #[Test]
    public function finiteDuplicateValuesStillDeduplicateInOriginalOrder(): void
    {
        $config = self::loadConfig();

        $type = (new UnionType([new FloatType([1.5, 2.5]), new FloatType([2.5, 1.5])]))->unwrapType($config);

        $schema = $type->toSchema($config);

        self::assertArrayNotHasKey('const', $schema);
        self::assertSame([1.5, 2.5], $schema['enum'] ?? null);
    }

    #[Test]
    public function jsonEquivalentIntegerAndFloatValuesDeduplicate(): void
    {
        $config = self::loadConfig();

        $type = (new UnionType([
            new IntegerType(1),
            new FloatType(1.0),
        ]))->unwrapType($config);

        $schema = $type->toSchema($config);

        self::assertSame(1, $schema['const'] ?? null);
        self::assertArrayNotHasKey('enum', $schema);

        $context = new TypeScriptRenderContext(new Scope($config), $config->getTypeScriptConfig());

        self::assertSame('1', (new TypeConverter)->convertToTypeScriptType($type, $context));
    }

    #[Test]
    public function jsonDistinctLargeIntegerAndFloatValuesRemainSeparate(): void
    {
        $config = self::loadConfig();

        $type = (new UnionType([
            new IntegerType(9007199254740993),
            new FloatType(9007199254740992.0),
        ]))->unwrapType($config);

        self::assertSame([
            9007199254740993,
            9007199254740992.0,
        ], $type->toSchema($config)['enum'] ?? null);
    }

    #[Test]
    public function renderedNonFiniteSchemasAreJsonEncodable(): void
    {
        $config = self::loadConfig();

        $schemas = [
            (new FloatType(NAN))->toSchema($config),
            (new FloatType(INF))->toSchema($config),
            (new NumberType([1.5, -INF]))->toSchema($config),
        ];

        foreach ($schemas as $schema) {
            self::assertJson(json_encode($schema, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function nonFiniteFloatRendersAsPlainNumberInTypeScript(): void
    {
        $config = self::loadConfig();
        $context = new TypeScriptRenderContext(new Scope($config), $config->getTypeScriptConfig());
        $converter = new TypeConverter;

        self::assertSame('number', $converter->convertToTypeScriptType(new FloatType(NAN), $context));
        self::assertSame('number', $converter->convertToTypeScriptType(new NumberType([1.5, INF]), $context));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    private function withoutPhpErrors(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            return $callback();

        } finally {
            restore_error_handler();
        }
    }
}
