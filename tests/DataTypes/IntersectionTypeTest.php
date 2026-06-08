<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\GenericSubClass;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
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
    public function stringIntersectedWithIntegerYieldsIntegerString(): void
    {
        foreach ([[new StringType, new IntegerType], [new IntegerType, new StringType]] as [$a, $b]) {
            $schema = (new IntersectionType([$a, $b]))->toSchema($this->configWithCoerciveScalarOverlap());

            $this->assertSchemaArraysMatch([
                'type' => 'string',
                'format' => 'integer',
            ], $schema, 'type', 'schema');
        }
    }

    #[Test]
    public function stringIntersectedWithNumberYieldsNumericString(): void
    {
        foreach ([[new StringType, new NumberType], [new NumberType, new StringType]] as [$a, $b]) {
            $schema = (new IntersectionType([$a, $b]))->toSchema($this->configWithCoerciveScalarOverlap());

            $this->assertSchemaArraysMatch([
                'type' => 'string',
                'format' => 'numeric',
            ], $schema, 'type', 'schema');
        }
    }

    #[Test]
    public function stringIntersectedWithFloatYieldsNumericString(): void
    {
        $schema = (new IntersectionType([new StringType, new FloatType]))->toSchema($this->configWithCoerciveScalarOverlap());

        $this->assertSchemaArraysMatch([
            'type' => 'string',
            'format' => 'numeric',
        ], $schema, 'type', 'schema');
    }

    #[Test]
    public function integerIntersectedWithBoolYieldsZeroOneInteger(): void
    {
        foreach ([[new IntegerType, new BoolType], [new BoolType, new IntegerType]] as [$a, $b]) {
            $schema = (new IntersectionType([$a, $b]))->toSchema($this->configWithCoerciveScalarOverlap());

            $this->assertSchemaArraysMatch([
                'type' => 'integer',
                'enum' => [0, 1],
            ], $schema, 'type', 'schema');
        }
    }

    #[Test]
    public function stringIntersectedWithBoolYieldsZeroOneString(): void
    {
        $schema = (new IntersectionType([new StringType, new BoolType]))->toSchema($this->configWithCoerciveScalarOverlap());

        $this->assertSchemaArraysMatch([
            'type' => 'string',
            'enum' => ['0', '1'],
        ], $schema, 'type', 'schema');
    }

    #[Test]
    public function disjointScalarsStayEmptyWithoutCoercion(): void
    {
        $schema = (new IntersectionType([new StringType, new IntegerType]))->toSchema($this->configWithScalarValues());

        $this->assertSchemaArraysMatch(['enum' => []], $schema, 'type', 'schema');
    }

    #[Test]
    public function stringIntersectedWithArrayStaysEmptyIntersection(): void
    {
        $schema = (new IntersectionType([new StringType, new ArrayType(itemType: new IntegerType)]))
            ->toSchema($this->configWithCoerciveScalarOverlap());

        $this->assertSchemaArraysMatch(['enum' => []], $schema, 'type', 'schema');
    }

    #[Test]
    public function emptyIntersectionRetainsConflictingMembersAndRequiredOnNever(): void
    {
        $never = (new IntersectionType([new StringType, new BoolType]))
            ->setRequired(true)
            ->unwrapType($this->configWithScalarValues());

        self::assertInstanceOf(NeverType::class, $never);
        self::assertTrue($never->required);
        self::assertSame(
            [StringType::class, BoolType::class],
            array_map(fn (Type $type) => $type::class, $never->conflictingTypes),
        );
    }

    #[Test]
    public function distributedEmptyIntersectionRetainsConflictingMembersOnNever(): void
    {
        $never = (new IntersectionType([
            new NullType,
            new UnionType([new IntegerType, new FloatType]),
        ]))->setRequired(true)->unwrapType($this->configWithScalarValues());

        self::assertInstanceOf(NeverType::class, $never);
        self::assertTrue($never->required);
        self::assertSame(
            [NullType::class, UnionType::class],
            array_map(fn (Type $type) => $type::class, $never->conflictingTypes),
        );
    }

    #[Test]
    public function emptyIntersectionRendersAsUnknownByDefault(): void
    {
        $config = self::loadConfig();

        $schema = (new IntersectionType([new StringType, new BoolType]))->toSchema($config);

        $this->assertSchemaArraysMatch(['type' => 'string'], $schema, 'type', 'schema');
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
        $config->data['intersections']['render_empty_as_unknown'] = false;

        return $config;
    }

    private function configWithCoerciveScalarOverlap(): Config
    {
        $config = $this->configWithScalarValues();
        $config->data['intersections']['coercive_scalar_overlap'] = true;

        return $config;
    }

    private function unwrapFully(Type $type): Type
    {
        return $type->unwrapType($this->configWithScalarValues());
    }
}
