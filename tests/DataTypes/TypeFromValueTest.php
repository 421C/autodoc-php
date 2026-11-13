<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeFromValueTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aListBecomesAnIntKeyedArrayOfTheUnionOfItsValues(): void
    {
        $type = Type::fromValue([1, 'a']);

        $this->assertInstanceOf(ArrayType::class, $type);
        $this->assertSame([], $type->shape);
        $this->assertInstanceOf(IntegerType::class, $type->keyType);
        $this->assertNull($type->keyType->value);
        $this->assertInstanceOf(UnionType::class, $type->itemType);
        $this->assertCount(2, $type->itemType->types);
        $this->assertInstanceOf(IntegerType::class, $type->itemType->types[0]);
        $this->assertSame(1, $type->itemType->types[0]->value);
        $this->assertInstanceOf(StringType::class, $type->itemType->types[1]);
        $this->assertSame('a', $type->itemType->types[1]->value);
    }


    #[Test]
    public function aListRendersAsAnArrayOfItsValues(): void
    {
        $this->assertSame(
            '{"type":"array","items":{"type":"integer","enum":[1,2,3]}}',
            $this->encodeSchema([1, 2, 3], $this->configShowingValues()),
        );
    }


    #[Test]
    public function anAssociativeArrayBecomesAShapeWithEveryEntryRequired(): void
    {
        $this->assertSame(
            '{"type":"object","properties":{"name":{"type":"string","const":"Mars"},"moons":{"type":"integer","const":2}},"required":["name","moons"]}',
            $this->encodeSchema(['name' => 'Mars', 'moons' => 2], $this->configShowingValues()),
        );
    }


    #[Test]
    public function aListInsideAnAssociativeArrayBecomesAnArrayOfItsValues(): void
    {
        $this->assertSame(
            '{"type":"object","properties":{"moons":{"type":"array","items":{"type":"string","enum":["Phobos","Deimos"]}}},"required":["moons"]}',
            $this->encodeSchema(['moons' => ['Phobos', 'Deimos']], $this->configShowingValues()),
        );
    }


    #[Test]
    public function anEmptyArrayBecomesAnUntypedArray(): void
    {
        $type = Type::fromValue([], $this->configShowingValues());

        $this->assertInstanceOf(ArrayType::class, $type);
        $this->assertSame([], $type->shape);
        $this->assertNull($type->itemType);
        $this->assertNull($type->keyType);
    }


    #[Test]
    public function listsStayShapesAtEveryDepthWhenPartialShapesAreResolved(): void
    {
        $config = $this->configShowingValues();
        $config->data['arrays']['resolve_partial_shapes'] = true;

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer","const":1},{"type":"array","prefixItems":[{"type":"string","const":"a"}],"minItems":1,"maxItems":1}],"minItems":2,"maxItems":2}',
            $this->encodeSchema([1, ['a']], $config),
        );
    }


    private function configShowingValues(): Config
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        return $config;
    }


    private function encodeSchema(mixed $value, Config $config): string
    {
        return json_encode(Type::fromValue($value, $config)->toSchema($config), JSON_THROW_ON_ERROR);
    }
}
