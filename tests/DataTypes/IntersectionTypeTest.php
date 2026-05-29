<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntersectionTypeTest extends TestCase
{
    use ComparesSchemaArrays;
    use LoadsConfig;

    #[Test]
    public function scalarLiteralIntersectionKeepsOnlySharedValues(): void
    {
        $schema = (new IntersectionType([
            new StringType(['json', 'xml']),
            new StringType('json'),
        ]))->toSchema($this->configWithScalarValues());

        $this->assertSchemaArraysMatch([
            'type' => 'string',
            'const' => 'json',
        ], $schema, 'type', 'schema');
    }

    #[Test]
    public function numericIntersectionKeepsTheMoreSpecificNumberType(): void
    {
        $left = (new IntersectionType([
            new IntegerType,
            new NumberType,
        ]))->unwrapType($this->configWithScalarValues());

        $right = (new IntersectionType([
            new NumberType,
            new IntegerType,
        ]))->unwrapType($this->configWithScalarValues());

        $left = $this->unwrapFully($left);
        $right = $this->unwrapFully($right);

        self::assertInstanceOf(IntegerType::class, $left);
        self::assertInstanceOf(IntegerType::class, $right);
    }

    #[Test]
    public function objectClassIntersectionKeepsTheMoreSpecificClass(): void
    {
        $left = (new IntersectionType([
            new ObjectType(className: GenericClass::class),
            new ObjectType(className: GenericSubClass::class),
        ]))->unwrapType($this->configWithScalarValues());

        $right = (new IntersectionType([
            new ObjectType(className: GenericSubClass::class),
            new ObjectType(className: GenericClass::class),
        ]))->unwrapType($this->configWithScalarValues());

        $left = $this->unwrapFully($left);
        $right = $this->unwrapFully($right);

        self::assertInstanceOf(ObjectType::class, $left);
        self::assertSame(GenericSubClass::class, $left->className);
        self::assertInstanceOf(ObjectType::class, $right);
        self::assertSame(GenericSubClass::class, $right->className);
    }

    #[Test]
    public function arrayShapeIntersectionCombinesDistinctKeys(): void
    {
        $left = new ArrayType(shape: [
            'id' => (new IntegerType)->setRequired(true),
        ]);

        $right = new ArrayType(shape: [
            'name' => (new StringType)->setRequired(true),
        ]);

        $schema = (new IntersectionType([$left, $right]))->toSchema($this->configWithScalarValues());

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id', 'name'],
        ], $schema, 'type', 'schema');
    }

    #[Test]
    public function arrayShapeIntersectionWithBroadArrayKeepsRequiredKeys(): void
    {
        $shape = new ArrayType(shape: [
            'id' => (new IntegerType)->setRequired(true),
        ]);

        $schema = (new IntersectionType([new ArrayType, $shape]))->toSchema($this->configWithScalarValues());

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ], $schema, 'type', 'schema');
    }

    #[Test]
    public function arrayShapeIntersectionIntersectsSharedKeyTypes(): void
    {
        $left = new ArrayType(shape: [
            'type' => (new StringType(['json', 'xml']))->setRequired(true),
        ]);

        $right = new ArrayType(shape: [
            'type' => (new StringType('json'))->setRequired(true),
        ]);

        $schema = (new IntersectionType([$left, $right]))->toSchema($this->configWithScalarValues());

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'const' => 'json',
                ],
            ],
            'required' => ['type'],
        ], $schema, 'type', 'schema');
    }

    private function configWithScalarValues(): Config
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        return $config;
    }

    private function unwrapFully(Type $type): Type
    {
        return $type->unwrapType($this->configWithScalarValues());
    }
}
