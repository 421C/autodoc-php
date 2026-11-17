<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenArrayShapeSchemaTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function requiredKnownEntriesSetTheMinimumLengthOfAnOpenTuple(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true)],
        );

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":"boolean"},"minItems":2}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function optionalKnownEntriesAtTheEndDoNotRaiseTheMinimumLengthOfAnOpenTuple(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            shape: [new IntegerType()->setRequired(true), new StringType],
        );

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":"boolean"},"minItems":1}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function anOpenTupleKeepsALargerMinimumLengthThanItsRequiredKnownEntries(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            shape: [new IntegerType()->setRequired(true)],
            minItems: 3,
        );

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"},"minItems":3}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function requiredKnownEntriesOutnumberingTheMinimumLengthOfAnOpenTupleSetIt(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true)],
            minItems: 1,
        );

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"items":{"type":"boolean"},"minItems":2}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function aTupleNextToAKeyTypeWithoutAnItemTypeAllowsAnyOtherEntry(): void
    {
        $array = new ArrayType(
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
        );

        $this->assertSame('{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1}', $this->encodeSchema($array));
    }


    #[Test]
    public function aStringKeyedShapeNextToAKeyTypeWithoutAnItemTypeAllowsAnyOtherEntry(): void
    {
        $array = new ArrayType(
            keyType: new StringType,
            shape: ['name' => new StringType()->setRequired(true)],
        );

        $this->assertSame(
            '{"type":"object","properties":{"name":{"type":"string"}},"required":["name"],"additionalProperties":true}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function aClosedTupleIgnoresAMinimumLength(): void
    {
        $array = new ArrayType(
            shape: [new IntegerType()->setRequired(true), new StringType],
            minItems: 5,
        );

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":1,"maxItems":2}',
            $this->encodeSchema($array),
        );
    }


    private function encodeSchema(Type $type): string
    {
        $config = self::loadConfig();

        return json_encode(SchemaJson::castPropertiesToObjects($type->unwrapType($config)->toSchema($config)), JSON_THROW_ON_ERROR);
    }
}
