<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\IntKeyOrder;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NonListArraySchemaTest extends TestCase
{
    use LoadsConfig;

    private const string INTEGER_LIST = '{"type":"array","items":{"type":"integer"}}';
    private const string INTEGER_LIST_OR_MAP ='{"anyOf":[{"type":"array","items":{"type":"integer"}},{"type":"object","additionalProperties":{"type":"integer"}}]}';
    private const string INTEGER_MAP = '{"type":"object","additionalProperties":{"type":"integer"}}';

    #[Test]
    public function anIntKeyedPairThatMayBeAMapRendersAsAListOrAMap(): void
    {
        $array = new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->encodeSchema($array));
    }


    #[Test]
    public function anIntKeyedPairThatCannotBeAListRendersAsAMap(): void
    {
        $array = new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::NonSequential);

        $this->assertSame(self::INTEGER_MAP, $this->encodeSchema($array));
    }


    #[Test]
    public function aShapeThatCannotBeAListRendersAsAnObject(): void
    {
        $shape = new ArrayType(shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true)], intKeyOrder: IntKeyOrder::NonSequential);

        $this->assertSame(
            '{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}},"required":["0","1"]}',
            $this->encodeSchema($shape),
        );
        $this->assertFalse($shape->isTuple());
    }


    #[Test]
    public function aStringKeyedPairStaysAMapWhenItMayBeAMap(): void
    {
        $array = new ArrayType(itemType: new IntegerType, keyType: new StringType, intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(self::INTEGER_MAP, $this->encodeSchema($array));
    }


    #[Test]
    public function documentationAndBoundsGoOnTheRightBranches(): void
    {
        $array = new ArrayType(itemType: new IntegerType, minItems: 1, maxItems: 5, intKeyOrder: IntKeyOrder::MaybeSequential);
        $array->description = 'Scores.';
        $array->deprecated = true;

        $this->assertSame(
            '{"anyOf":[{"type":"array","items":{"type":"integer"},"minItems":1,"maxItems":5},{"type":"object","additionalProperties":{"type":"integer"},"minProperties":1,"maxProperties":5}],"description":"Scores.","deprecated":true}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function aShapeWithIndependentOptionalEntriesRendersAsATupleOrAnObject(): void
    {
        $shape = new ArrayType(shape: [new IntegerType, new StringType], intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"maxItems":2},{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}}}]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function aShapeWithASingleTrailingOptionalEntryIsAlwaysATuple(): void
    {
        $shape = new ArrayType(shape: [new IntegerType()->setRequired(true), new StringType], intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":1,"maxItems":2}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function aRequiredEntryAfterAnOptionalOneRequiresBothInTheTupleBranch(): void
    {
        $shape = new ArrayType(shape: [new IntegerType, new StringType()->setRequired(true)], intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2},{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}},"required":["1"]}]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function optionalEntriesPastAGapLeaveTheLeadingEntriesAsTheTupleBranch(): void
    {
        $shape = new ArrayType(shape: [0 => new IntegerType()->setRequired(true), 2 => new StringType], intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1,"maxItems":1},{"type":"object","properties":{"0":{"type":"integer"},"2":{"type":"string"}},"required":["0"]}]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function aRequiredEntryPastAGapKeepsTheShapeAnObject(): void
    {
        $shape = new ArrayType(shape: [0 => new IntegerType, 2 => new StringType()->setRequired(true)], intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(
            '{"type":"object","properties":{"0":{"type":"integer"},"2":{"type":"string"}},"required":["2"]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function optionalEntriesBeforeTheIndexesAreLeftOutOfTheTupleBranch(): void
    {
        $shape = new ArrayType(shape: ['label' => new StringType, 0 => new IntegerType()->setRequired(true)], intKeyOrder: IntKeyOrder::MaybeSequential);

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1,"maxItems":1},{"type":"object","properties":{"label":{"type":"string"},"0":{"type":"integer"}},"required":["0"]}]}',
            $this->encodeSchema($shape),
        );
    }


    #[Test]
    public function aShapeThatMayBeAMapIsNotATuple(): void
    {
        $this->assertFalse(new ArrayType(shape: [new IntegerType, new StringType], intKeyOrder: IntKeyOrder::MaybeSequential)->isTuple());
        $this->assertTrue(new ArrayType(shape: [new IntegerType, new StringType])->isTuple());
    }


    #[Test]
    public function aUnionWithAListThatMayBeAMapMayBeAMap(): void
    {
        $union = new UnionType([
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType),
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::MaybeSequential),
        ]);

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->encodeSchema($union));
    }


    #[Test]
    public function aUnionOfAListAndAMapMayBeEither(): void
    {
        $union = new UnionType([
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType),
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::NonSequential),
        ]);

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->encodeSchema($union));
    }


    #[Test]
    public function anIntersectionWithAListIsAList(): void
    {
        $intersection = new IntersectionType([
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType),
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::MaybeSequential),
        ]);

        $this->assertSame('{"type":"array","items":{"type":"integer"}}', $this->encodeSchema($intersection));
    }


    #[Test]
    public function aUnionOfShapesMayBeAMapWhenEitherMayBe(): void
    {
        $union = new UnionType([
            new ArrayType(shape: [new IntegerType()->setRequired(true), new IntegerType]),
            new ArrayType(shape: [new IntegerType, new IntegerType], intKeyOrder: IntKeyOrder::MaybeSequential),
        ]);

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"},{"type":"integer"}],"maxItems":2},{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"integer"}}}]}',
            $this->encodeSchema($union),
        );
    }


    #[Test]
    public function aUnionOfStringKeyedMapsStaysAMap(): void
    {
        $union = new UnionType([
            new ArrayType(itemType: new IntegerType, keyType: new StringType),
            new ArrayType(itemType: new IntegerType(5), keyType: new StringType),
        ]);

        $this->assertSame(self::INTEGER_MAP, $this->encodeSchema($union));
    }


    #[Test]
    public function aListShapeDoesNotMergeWithAShapeThatIsNeverAList(): void
    {
        $config = self::loadConfig();
        $config->data['arrays']['merge_shapes_in_type_unions'] = true;

        $union = new UnionType([
            new ArrayType(shape: [0 => new IntegerType()->setRequired(true), 1 => new IntegerType()->setRequired(true)]),
            new ArrayType(shape: [0 => new IntegerType()->setRequired(true), 2 => new IntegerType()->setRequired(true)]),
        ]);

        $this->assertSame(
            '{"anyOf":[{"type":"array","prefixItems":[{"type":"integer"},{"type":"integer"}],"minItems":2,"maxItems":2},{"type":"object","properties":{"0":{"type":"integer"},"2":{"type":"integer"}},"required":["0","2"]}]}',
            $this->encodeSchema($union, $config),
        );
    }


    #[Test]
    public function anIntKeyedPairThatCannotBeAListIsARecordInTypeScript(): void
    {
        $config = self::loadConfig();
        $context = new TypeScriptRenderContext(new Scope($config), $config->getTypeScriptConfig());
        $array = new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::NonSequential);

        $this->assertSame('Record<string, number>', new TypeConverter()->convertToTypeScriptType($array, $context));
    }


    #[Test]
    public function aPairThatMayBeAListOrAMapIsParenthesizedInAnIntersection(): void
    {
        $config = self::loadConfig();
        $context = new TypeScriptRenderContext(new Scope($config), $config->getTypeScriptConfig());
        $intersection = new IntersectionType([
            new ArrayType(shape: ['a' => new IntegerType()->setRequired(true)]),
            new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::MaybeSequential),
        ]);

        $this->assertSame(
            "{\n    a: number\n}&(number[]|Record<string, number>)",
            new TypeConverter()->convertToTypeScriptType($intersection, $context),
        );
    }


    #[Test]
    public function removingOrReorderingEntriesWithTheirKeysMayLeaveGaps(): void
    {
        $config = self::loadConfig();
        $ints = fn () => new ArrayType(itemType: new IntegerType, keyType: new IntegerType, minItems: 1);

        $this->assertSame(self::INTEGER_LIST_OR_MAP, $this->encodeSchema($ints()->markItemsOptional()));
        $this->assertSame(
            '{"anyOf":[{"type":"array","items":{"type":"integer"},"minItems":1},{"type":"object","additionalProperties":{"type":"integer"},"minProperties":1}]}',
            $this->encodeSchema($ints()->reorderItems($config)),
        );
    }


    #[Test]
    public function renumberingKeysMakesAListAgain(): void
    {
        $config = self::loadConfig();
        $filteredInts = fn () => new ArrayType(itemType: new IntegerType, keyType: new IntegerType, intKeyOrder: IntKeyOrder::MaybeSequential);
        $filteredMixedKeys = fn () => new ArrayType(
            itemType: new IntegerType,
            keyType: new UnionType([new IntegerType, new StringType]),
            intKeyOrder: IntKeyOrder::MaybeSequential,
        );

        $this->assertSame(self::INTEGER_LIST, $this->encodeSchema($filteredInts()->renumberKeys($config)));
        $this->assertSame(self::INTEGER_LIST, $this->encodeSchema($filteredInts()->toList($config)));
        $this->assertSame(self::INTEGER_MAP, $this->encodeSchema($filteredMixedKeys()->renumberKeys($config)));
        $this->assertSame(self::INTEGER_LIST, $this->encodeSchema($filteredMixedKeys()->toList($config)));
    }


    #[Test]
    public function renumberingTheKeysOfAShapeKeepsItsEntriesInOrder(): void
    {
        $config = self::loadConfig();
        $shape = new ArrayType(
            shape: [
                2 => new IntegerType()->setRequired(true),
                'name' => new StringType()->setRequired(true),
                0 => new StringType()->setRequired(true),
            ],
            intKeyOrder: IntKeyOrder::MaybeSequential,
        );

        $this->assertSame(
            '{"type":"object","properties":{"0":{"type":"integer"},"name":{"type":"string"},"1":{"type":"string"}},"required":["0","name","1"]}',
            $this->encodeSchema($shape->renumberKeys($config)),
        );
    }


    #[Test]
    public function anArrayWithoutKeyOrItemTypesKeepsTheListAssumption(): void
    {
        $config = self::loadConfig();
        $untypedArray = $this->encodeSchema(new ArrayType);

        $this->assertSame($untypedArray, $this->encodeSchema(new ArrayType()->markItemsOptional()));
        $this->assertSame($untypedArray, $this->encodeSchema(new ArrayType()->reorderItems($config)));
    }


    private function encodeSchema(Type $type, ?Config $config = null): string
    {
        $config ??= self::loadConfig();

        return json_encode(SchemaJson::castPropertiesToObjects($type->unwrapType($config)->toSchema($config)), JSON_THROW_ON_ERROR);
    }
}
