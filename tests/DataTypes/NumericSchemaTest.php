<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NumericSchemaTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function fractionalBoundsRenderAsIs(): void
    {
        $config = self::loadConfig();

        $this->assertSame(
            ['type' => 'number', 'format' => 'float', 'minimum' => 0.01],
            (new FloatType(minimum: 0.01))->toSchema($config),
        );

        $this->assertSame(
            ['type' => 'number', 'minimum' => -1.5, 'maximum' => 99.5],
            (new NumberType(minimum: -1.5, maximum: 99.5))->toSchema($config),
        );
    }


    #[Test]
    public function aUnionOfEqualFractionalBoundsKeepsThem(): void
    {
        $type = (new UnionType([
            new FloatType(minimum: 0.5, maximum: 9.5),
            new FloatType(minimum: 0.5, maximum: 9.5),
        ]))->unwrapType(self::loadConfig());

        $this->assertInstanceOf(FloatType::class, $type);
        $this->assertSame([0.5, 9.5], [$type->minimum, $type->maximum]);
    }


    #[Test]
    public function anIntegerNarrowedFromANumberKeepsIntegerBounds(): void
    {
        $type = (new IntersectionType([
            new IntegerType(minimum: 1, maximum: 10),
            new NumberType(minimum: 1, maximum: 10),
        ]))->unwrapType(self::loadConfig());

        $this->assertInstanceOf(IntegerType::class, $type);
        $this->assertSame([1, 10], [$type->minimum, $type->maximum]);
    }


    #[Test]
    public function aUnionOfANumberAndANumericStringKeepsBoth(): void
    {
        $schema = (new UnionType([
            new FloatType(minimum: 0, maximum: 100),
            new NumberType(minimum: 0, maximum: 100, isString: true),
        ]))->toSchema(self::loadConfig());

        $this->assertEquals(
            [
                'anyOf' => [
                    ['type' => 'number', 'format' => 'float', 'minimum' => 0, 'maximum' => 100],
                    ['type' => 'string', 'format' => 'numeric', 'minimum' => 0, 'maximum' => 100],
                ],
            ],
            $schema,
        );
    }


    #[Test]
    public function aUnionOfNumericStringsStaysANumericString(): void
    {
        $config = self::loadConfig();

        $integerString = new IntegerType;
        $integerString->isString = true;

        $type = (new UnionType([$integerString, clone $integerString]))->unwrapType($config);

        $this->assertInstanceOf(IntegerType::class, $type);
        $this->assertTrue($type->isString);

        $type = (new UnionType([clone $integerString, new NumberType(isString: true)]))->unwrapType($config);

        $this->assertInstanceOf(NumberType::class, $type);
        $this->assertTrue($type->isString);
    }


    #[Test]
    public function anIntersectionOfNumericStringsStaysANumericString(): void
    {
        $integerString = new IntegerType;
        $integerString->isString = true;

        $type = (new IntersectionType([
            new NumberType(isString: true),
            $integerString,
        ]))->unwrapType(self::loadConfig());

        $this->assertInstanceOf(IntegerType::class, $type);
        $this->assertTrue($type->isString);
    }


    #[Test]
    public function aBoundedNumericStringStaysAString(): void
    {
        $config = self::loadConfig();

        $integerString = new IntegerType(minimum: 1);
        $integerString->isString = true;

        $this->assertEquals(
            ['type' => 'string', 'format' => 'integer', 'minimum' => 1],
            $integerString->toSchema($config),
        );

        $this->assertEquals(
            ['type' => 'string', 'format' => 'numeric', 'minimum' => 0.5, 'maximum' => 10],
            (new NumberType(minimum: 0.5, maximum: 10, isString: true))->toSchema($config),
        );
    }


    #[Test]
    public function aBoundedNumericStringKeepsItsBoundsNextToThePattern(): void
    {
        $config = self::loadConfig();
        $config->data['openapi']['use_pattern_for_numeric_strings'] = true;

        $integerString = new IntegerType(maximum: 99);
        $integerString->isString = true;

        $this->assertEquals(
            ['type' => 'string', 'pattern' => '^[0-9]+$', 'maximum' => 99],
            $integerString->toSchema($config),
        );

        $this->assertEquals(
            ['type' => 'string', 'pattern' => '^[+-]?[0-9]+(\.[0-9]+)?$', 'minimum' => 0],
            (new NumberType(minimum: 0, isString: true))->toSchema($config),
        );
    }
}
