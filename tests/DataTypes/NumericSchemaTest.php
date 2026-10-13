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
