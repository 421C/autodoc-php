<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\DocBlock\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TupleSchemaTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aListShapeWithRequiredEntriesRendersAsATuple(): void
    {
        $tuple = new ArrayType(shape: [
            new IntegerType()->setRequired(true),
            new StringType()->setRequired(true),
        ]);

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2}',
            $this->encodeSchema($tuple),
        );
    }


    #[Test]
    public function trailingOptionalEntriesLowerMinItems(): void
    {
        $tuple = new ArrayType(shape: [
            new IntegerType()->setRequired(true),
            new StringType,
        ]);

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":1,"maxItems":2}',
            $this->encodeSchema($tuple),
        );
    }


    #[Test]
    public function aRequiredEntryAfterAnOptionalOneRendersAsAnObject(): void
    {
        $shape = new ArrayType(shape: [
            new IntegerType,
            new StringType()->setRequired(true),
        ]);

        $this->assertSame(
            '{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}},"required":["1"]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function nonSequentialIntKeysRenderAsAnObject(): void
    {
        $shape = new ArrayType(shape: [
            1 => new IntegerType()->setRequired(true),
            3 => new StringType()->setRequired(true),
        ]);

        $this->assertSame(
            '{"type":"object","properties":{"1":{"type":"integer"},"3":{"type":"string"}},"required":["1","3"]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function objectPropertiesNamedLikeListIndexesRenderAsAnObject(): void
    {
        $phpDoc = new PhpDoc(docComment: '/** */', scope: new Scope(self::loadConfig()));

        $object = $phpDoc->createUnresolvedType($phpDoc->createTypeNode("object{'0': int, '1': string}"));

        $this->assertSame(
            '{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}},"required":["0","1"]}',
            $this->encodeSchema($object),
        );
    }


    #[Test]
    public function anObjectWithoutPropertiesHasNoPropertiesKey(): void
    {
        $this->assertSame('{"type":"object"}', $this->encodeSchema(new ObjectType));
    }


    #[Test]
    public function aTupleKeepsItsDocumentation(): void
    {
        $tuple = new ArrayType(shape: [
            new IntegerType()->setRequired(true),
            new StringType()->setRequired(true),
        ]);

        $tuple->description = 'A coordinate.';
        $tuple->examples = [[5, 'north']];
        $tuple->deprecated = true;
        $tuple->deprecatedDescription = 'Use the point object.';

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2,"description":"A coordinate.","examples":[[5,"north"]],"deprecated":true,"x-deprecated-description":"Use the point object."}',
            $this->encodeSchema($tuple),
        );
    }


    private function encodeSchema(Type $type): string
    {
        return json_encode(SchemaJson::castPropertiesToObjects($type->toSchema(self::loadConfig())), JSON_THROW_ON_ERROR);
    }
}
