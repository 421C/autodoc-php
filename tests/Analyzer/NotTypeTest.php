<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Narrowing\NotType;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotTypeTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function itRemovesStringLiteralValuesFromFiniteStringTypes(): void
    {
        $type = $this->apply(new StringType(['json', 'xml']), new StringType('json'));

        self::assertInstanceOf(StringType::class, $type);
        self::assertSame('xml', $type->value);
    }

    #[Test]
    public function itRemovesIntegerLiteralValuesFromFiniteIntegerTypes(): void
    {
        $type = $this->apply(new IntegerType([200, 404]), new IntegerType(404));

        self::assertInstanceOf(IntegerType::class, $type);
        self::assertSame(200, $type->value);
    }

    #[Test]
    public function itRemovesFloatLiteralValuesFromFiniteFloatTypes(): void
    {
        $type = $this->apply(new FloatType([1.5, 2.5]), new FloatType(1.5));

        self::assertInstanceOf(FloatType::class, $type);
        self::assertSame(2.5, $type->value);
    }

    #[Test]
    public function itRemovesNumberLiteralValuesFromFiniteNumberTypes(): void
    {
        $type = $this->apply(new NumberType([1, 2.5]), new NumberType(1));

        self::assertInstanceOf(NumberType::class, $type);
        self::assertSame(2.5, $type->value);
    }

    #[Test]
    public function itRemovesBooleanLiteralValues(): void
    {
        $type = $this->apply(new BoolType, new BoolType(true));

        self::assertInstanceOf(BoolType::class, $type);
        self::assertFalse($type->value);
    }

    private function apply(Type $base, Type $excludedType): Type
    {
        return (new NotType($excludedType))->apply($base, new Scope(self::loadConfig()));
    }
}
