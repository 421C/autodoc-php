<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\IntKeyOrder;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeConverter;
use AutoDoc\TypeScript\TypeScriptRenderContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenArrayShapeSchemaTest extends TestCase
{
    use LoadsConfig;

    private const string BOOLEAN_LIST_OR_MAP = '{"anyOf":[{"type":"array","items":{"type":"boolean"}},{"type":"object","additionalProperties":{"type":"boolean"}}]}';

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


    #[Test]
    public function tupleEntriesTheOtherEntriesAllowAreNotDocumentedOneByOne(): void
    {
        $array = new ArrayType(
            itemType: new UnionType([new IntegerType, new NullType]),
            shape: [new IntegerType()->setRequired(true), new IntegerType],
        );

        $this->assertNull($array->getDocumentedTupleEntries(self::loadConfig()));
        $this->assertSame('{"type":"array","items":{"type":["integer","null"]}}', $this->encodeSchema($array));
        $this->assertSame('Array<number|null>', $this->toTypeScript($array));
    }


    #[Test]
    public function tupleEntriesAreDocumentedOneByOneWhenTheOtherEntriesDoNotAllowOneOfThem(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            shape: [new StringType()->setRequired(true), new IntegerType()->setRequired(true)],
        );

        $this->assertCount(2, $array->getDocumentedTupleEntries(self::loadConfig()) ?? []);
        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"string"},{"type":"integer"}],"items":{"type":"integer"},"minItems":2}',
            $this->encodeSchema($array),
        );
        $this->assertSame('[string, number, ...number[]]', $this->toTypeScript($array));
    }


    #[Test]
    public function aClosedTupleDocumentsEveryEntryOneByOne(): void
    {
        $tuple = new ArrayType(shape: [new IntegerType()->setRequired(true), new IntegerType()->setRequired(true)]);

        $this->assertCount(2, $tuple->getDocumentedTupleEntries(self::loadConfig()) ?? []);
        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"integer"}],"minItems":2,"maxItems":2}',
            $this->encodeSchema($tuple),
        );
    }


    #[Test]
    public function otherEntriesOfAnUnknownTypeAllowOnlyTupleEntriesOfAnUnknownType(): void
    {
        $knownBeforeUnknown = new ArrayType(
            itemType: new UnknownType,
            shape: [new IntegerType()->setRequired(true)],
        );
        $unknownBeforeUnknown = new ArrayType(
            itemType: new UnknownType,
            shape: [new UnknownType()->setRequired(true)],
        );

        $this->assertSame('{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1}', $this->encodeSchema($knownBeforeUnknown));
        $this->assertSame('[number, ...unknown[]]', $this->toTypeScript($knownBeforeUnknown));

        $this->assertSame('{"type":"array","items":{"type":"string"}}', $this->encodeSchema($unknownBeforeUnknown));
        $this->assertSame('unknown[]', $this->toTypeScript($unknownBeforeUnknown));
    }


    #[Test]
    public function aPlainListKeepsItsOwnMinimumLengthWhateverItsRequiredLeadingEntriesAre(): void
    {
        $listOfIntegers = fn (int $requiredEntryCount, ?int $minItems) => new ArrayType(
            itemType: new IntegerType,
            shape: array_fill(0, $requiredEntryCount, new IntegerType()->setRequired(true)),
            minItems: $minItems,
        );

        $this->assertSame('{"type":"array","items":{"type":"integer"},"minItems":3}', $this->encodeSchema($listOfIntegers(1, 3)));
        $this->assertSame('{"type":"array","items":{"type":"integer"},"minItems":1}', $this->encodeSchema($listOfIntegers(2, 1)));
        $this->assertSame('{"type":"array","items":{"type":"integer"}}', $this->encodeSchema($listOfIntegers(2, null)));
    }


    #[Test]
    public function aListThatMayHaveGapsListsItsLeadingEntriesOnlyAsProperties(): void
    {
        $array = new ArrayType(
            itemType: new UnionType([new IntegerType, new NullType]),
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
            intKeyOrder: IntKeyOrder::MaybeSequential,
        );

        $this->assertSame(
            '{"anyOf":['
                . '{"type":"array","items":{"type":["integer","null"]}},'
                . '{"type":"object","properties":{"0":{"type":"integer"}},"required":["0"],"additionalProperties":{"type":["integer","null"]}}'
                . ']}',
            $this->encodeSchema($array),
        );
        $this->assertSame(
            implode("\n", [
                'Array<number|null>|{',
                "    '0': number",
                '    [key: string]: number|null',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aPropertyThatIsNotAssignableToTheItemTypeWidensTheIndexSignature(): void
    {
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    [key: string]: string|number',
                '}',
            ]),
            $this->toTypeScript($this->errorWithIntegerEntries()),
        );
    }


    #[Test]
    public function anOptionalPropertyAddsUndefinedToTheIndexSignature(): void
    {
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    details?: string',
                '    [key: string]: string|number|undefined',
                '}',
            ]),
            $this->toTypeScript($this->errorAndOptionalDetailsWithIntegerEntries()),
        );
    }


    #[Test]
    public function anUnknownItemTypeMakesTheIndexSignatureUnknown(): void
    {
        $array = new ArrayType(
            itemType: new UnknownType,
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true), 'details' => new StringType],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    details?: string',
                '    [key: string]: unknown',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aKeyTypeWithoutAnItemTypeMakesTheIndexSignatureUnknown(): void
    {
        $array = new ArrayType(
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    [key: string]: unknown',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function anUnknownPropertyMakesTheIndexSignatureUnknown(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            keyType: new StringType,
            shape: ['meta' => new UnknownType()->setRequired(true), 'name' => new StringType()->setRequired(true)],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                '    meta: unknown',
                '    name: string',
                '    [key: string]: unknown',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aPropertyWithoutPossibleValuesMakesTheIndexSignatureUnknown(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            keyType: new StringType,
            shape: ['conflict' => new NeverType(required: true)],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                '    conflict: unknown',
                '    [key: string]: unknown',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aLiteralPropertyValueStaysInTheIndexSignature(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            keyType: new StringType,
            shape: ['status' => new StringType('ok')->setRequired(true)],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                "    status: 'ok'",
                "    [key: string]: 'ok'|number",
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aLiteralPropertyValueOfTheItemTypeDoesNotWidenTheIndexSignature(): void
    {
        $array = new ArrayType(
            itemType: new StringType,
            keyType: new StringType,
            shape: ['status' => new StringType('ok')->setRequired(true)],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                "    status: 'ok'",
                '    [key: string]: string',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aQuotedPropertyNameSitsNextToTheIndexSignature(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            keyType: new StringType,
            shape: ['first-name' => new StringType()->setRequired(true)],
        );

        $this->assertSame(
            implode("\n", [
                '{',
                "    'first-name': string",
                '    [key: string]: string|number',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function theIndexSignatureEndsWithASemicolonWhenPropertiesDo(): void
    {
        $config = self::loadConfig();
        $config->data['typescript']['add_semicolons'] = true;

        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string;',
                '    [key: string]: string|number;',
                '}',
            ]),
            $this->toTypeScript($this->errorWithIntegerEntries(), $config),
        );
    }


    #[Test]
    public function aClosedShapeHasNoIndexSignature(): void
    {
        $array = new ArrayType(shape: ['error' => new StringType()->setRequired(true)]);

        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function theRootOnlyOptionDropsTheIndexSignature(): void
    {
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '}',
            ]),
            $this->toRootTypeScript($this->errorAndOptionalDetailsWithIntegerEntries(), ['only' => ['error']]),
        );
    }


    #[Test]
    public function theRootOmitOptionKeepsTheIndexSignatureOfTheRemainingProperties(): void
    {
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    [key: string]: string|number',
                '}',
            ]),
            $this->toRootTypeScript($this->errorAndOptionalDetailsWithIntegerEntries(), ['omit' => ['details']]),
        );
    }


    #[Test]
    public function theRootOmitOptionLeavesTheIndexSignatureWhenNoPropertyRemains(): void
    {
        $this->assertSame(
            implode("\n", [
                '{',
                '    [key: string]: number',
                '}',
            ]),
            $this->toRootTypeScript($this->errorAndOptionalDetailsWithIntegerEntries(), ['omit' => ['error', 'details']]),
        );
    }


    #[Test]
    public function theRootWithOptionAddsItsPropertyToTheIndexSignature(): void
    {
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    active: boolean',
                '    [key: string]: string|boolean|number',
                '}',
            ]),
            $this->toRootTypeScript($this->errorWithIntegerEntries(), ['with' => ['active' => new BoolType()->setRequired(true)]]),
        );
    }


    #[Test]
    public function anOpenTupleEndsWithARestElement(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true)],
        );

        $this->assertSame('[number, string, ...boolean[]]', $this->toTypeScript($array));
    }


    #[Test]
    public function anOptionalTupleEntrySitsBeforeTheRestElement(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            shape: [new IntegerType()->setRequired(true), new StringType],
        );

        $this->assertSame('[number, string?, ...boolean[]]', $this->toTypeScript($array));
    }


    #[Test]
    public function aTupleNextToAKeyTypeWithoutAnItemTypeHasAnUnknownRestElement(): void
    {
        $array = new ArrayType(
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
        );

        $this->assertSame('[number, ...unknown[]]', $this->toTypeScript($array));
    }


    #[Test]
    public function aTupleOfFourElementsIncludingTheRestElementSpansLines(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true), new BoolType()->setRequired(true)],
        );

        $this->assertSame(
            implode("\n", [
                '[',
                '    number,',
                '    string,',
                '    boolean,',
                '    ...number[],',
                ']',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aClosedTupleHasNoRestElement(): void
    {
        $array = new ArrayType(shape: [new IntegerType()->setRequired(true), new StringType]);

        $this->assertSame('[number, string?]', $this->toTypeScript($array));
    }


    #[Test]
    public function theKeyOrderDecidesWhetherAnOpenTupleIsAListOrAnObject(): void
    {
        $openTuple = fn (IntKeyOrder $intKeyOrder) => new ArrayType(
            itemType: new BoolType,
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
            intKeyOrder: $intKeyOrder,
        );

        $list = '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"},"minItems":1}';
        $object = '{"type":"object","properties":{"0":{"type":"integer"}},"required":["0"],"additionalProperties":{"type":"boolean"}}';
        $tsList = '[number, ...boolean[]]';
        $tsObject = implode("\n", [
            '{',
            "    '0': number",
            '    [key: string]: number|boolean',
            '}',
        ]);

        $this->assertSame($list, $this->encodeSchema($openTuple(IntKeyOrder::Sequential)));
        $this->assertSame($tsList, $this->toTypeScript($openTuple(IntKeyOrder::Sequential)));

        $this->assertSame('{"anyOf":[' . $list . ',' . $object . ']}', $this->encodeSchema($openTuple(IntKeyOrder::MaybeSequential)));
        $this->assertSame($tsList . '|' . $tsObject, $this->toTypeScript($openTuple(IntKeyOrder::MaybeSequential)));

        $this->assertSame($object, $this->encodeSchema($openTuple(IntKeyOrder::NonSequential)));
        $this->assertSame($tsObject, $this->toTypeScript($openTuple(IntKeyOrder::NonSequential)));
    }


    #[Test]
    public function aStringKeyNextToIntKeyedEntriesOutsideTheShapeMakesAnObject(): void
    {
        $array = new ArrayType(
            itemType: new IntegerType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}}',
            $this->encodeSchema($array),
        );
        $this->assertSame(
            implode("\n", [
                '{',
                '    error: string',
                '    [key: string]: string|number',
                '}',
            ]),
            $this->toTypeScript($array),
        );
    }


    #[Test]
    public function aTupleNextToEntriesOutsideTheShapeWhoseKeysMayBeStringsMakesAnObject(): void
    {
        $tupleWithKeysOutsideShape = fn (Type $keyType) => new ArrayType(
            itemType: new BoolType,
            keyType: $keyType,
            shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true)],
        );

        $arrays = [
            'stringKeys' => $tupleWithKeysOutsideShape(new StringType),
            'intOrStringKeys' => $tupleWithKeysOutsideShape(new UnionType([new IntegerType, new StringType])),
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($arrays),
                '{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}},"required":["0","1"],"additionalProperties":{"type":"boolean"}}',
            ),
            array_map($this->encodeSchema(...), $arrays),
        );
        $this->assertSame(
            array_fill_keys(array_keys($arrays), implode("\n", [
                '{',
                "    '0': number",
                "    '1': string",
                '    [key: string]: number|string|boolean',
                '}',
            ])),
            array_map(fn (ArrayType $array) => $this->toTypeScript($array), $arrays),
        );
    }


    #[Test]
    public function aKnownEntryAfterAnUnknownOneJoinsTheItemsOfAPlainList(): void
    {
        $arrays = [
            'sixthEntry' => new ArrayType(
                itemType: new BoolType,
                keyType: new IntegerType,
                shape: [5 => new IntegerType()->setRequired(true)],
            ),
            'firstAndThirdEntry' => new ArrayType(
                itemType: new BoolType,
                keyType: new IntegerType,
                shape: [0 => new IntegerType()->setRequired(true), 2 => new IntegerType()->setRequired(true)],
            ),
        ];

        $this->assertSame(
            array_fill_keys(array_keys($arrays), '{"type":"array","items":{"type":["integer","boolean"]}}'),
            array_map($this->encodeSchema(...), $arrays),
        );
        $this->assertSame(
            array_fill_keys(array_keys($arrays), 'Array<number|boolean>'),
            array_map(fn (ArrayType $array) => $this->toTypeScript($array), $arrays),
        );
    }


    #[Test]
    public function aPlainListWithAKnownEntryKeepsItsMinimumLength(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            keyType: new IntegerType,
            shape: [5 => new IntegerType()->setRequired(true)],
            minItems: 2,
        );

        $this->assertSame('{"type":"array","items":{"type":["integer","boolean"]},"minItems":2}', $this->encodeSchema($array));
    }


    #[Test]
    public function theEncodingHelpersFollowTheKeysInsideAndOutsideTheShape(): void
    {
        $config = self::loadConfig();

        $leadingEntry = fn (IntKeyOrder $intKeyOrder) => new ArrayType(
            itemType: new BoolType,
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
            intKeyOrder: $intKeyOrder,
        );

        $arrays = [
            'sequential' => $leadingEntry(IntKeyOrder::Sequential),
            'maybeSequential' => $leadingEntry(IntKeyOrder::MaybeSequential),
            'nonSequential' => $leadingEntry(IntKeyOrder::NonSequential),
            'laterEntry' => new ArrayType(itemType: new BoolType, shape: [5 => new IntegerType()->setRequired(true)]),
            'stringKeyInShape' => new ArrayType(itemType: new BoolType, shape: ['name' => new StringType()->setRequired(true)]),
            'optionalStringKeyInShape' => new ArrayType(itemType: new BoolType, shape: ['name' => new StringType]),
            'stringKeysOutsideShape' => new ArrayType(itemType: new BoolType, keyType: new StringType, shape: [new IntegerType()->setRequired(true)]),
        ];

        $this->assertSame(
            [
                'sequential' => ['list' => true, 'object' => false],
                'maybeSequential' => ['list' => true, 'object' => true],
                'nonSequential' => ['list' => false, 'object' => true],
                'laterEntry' => ['list' => true, 'object' => false],
                'stringKeyInShape' => ['list' => false, 'object' => true],
                'optionalStringKeyInShape' => ['list' => false, 'object' => true],
                'stringKeysOutsideShape' => ['list' => false, 'object' => true],
            ],
            array_map(
                fn (ArrayType $array) => ['list' => $array->mayEncodeAsList($config), 'object' => $array->mayEncodeAsObject($config)],
                $arrays,
            ),
        );
    }


    #[Test]
    public function tupleEntriesOfAnOpenShapeAreItsLeadingEntriesWhenItHasNoOtherKnownKey(): void
    {
        $leadingEntries = $this->intKeyedOpenShape([new IntegerType()->setRequired(true), new StringType])->getTupleEntries();
        $optionalBeforeRequired = $this->intKeyedOpenShape([new IntegerType, new StringType()->setRequired(true)])->getTupleEntries();

        $this->assertNotNull($leadingEntries);
        $this->assertCount(2, $leadingEntries);

        $this->assertNotNull($optionalBeforeRequired);
        $this->assertSame([true, true], array_map(fn (Type $entry) => $entry->required, $optionalBeforeRequired));

        $this->assertNull($this->intKeyedOpenShape([5 => new IntegerType])->getTupleEntries());
        $this->assertNull($this->intKeyedOpenShape([0 => new IntegerType, 2 => new IntegerType])->getTupleEntries());
        $this->assertNull($this->intKeyedOpenShape([new IntegerType], IntKeyOrder::NonSequential)->getTupleEntries());
    }


    #[Test]
    public function reorderingAnOpenTupleWithItsKeysFoldsItIntoAListOrAMap(): void
    {
        $array = new ArrayType(
            itemType: new BoolType,
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
        );

        $this->assertSame(
            '{"anyOf":[{"type":"array","items":{"type":["integer","boolean"]}},{"type":"object","additionalProperties":{"type":["integer","boolean"]}}]}',
            $this->encodeSchema($array->reorderItems(self::loadConfig())),
        );
    }


    #[Test]
    public function reorderingAnOpenShapeThatIsNeverAListKeepsItsKnownKeys(): void
    {
        $array = $this->errorWithIntegerEntries()->reorderItems(self::loadConfig());

        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function removingTheOnlyKnownEntryOfAnOpenTupleLeavesAListThatMayHaveGaps(): void
    {
        $array = $this->intKeyedOpenShape([new IntegerType()->setRequired(true)])->removeItemFromArray(0);

        $this->assertSame(self::BOOLEAN_LIST_OR_MAP, $this->encodeSchema($array));
        $this->assertSame(IntKeyOrder::MaybeSequential, $array->intKeyOrder);
    }


    #[Test]
    public function removingOneOfTwoKnownEntriesOfAnOpenTupleKeepsTheOther(): void
    {
        $array = $this->intKeyedOpenShape([new IntegerType()->setRequired(true), new StringType()->setRequired(true)])->removeItemFromArray(0);

        $this->assertSame(
            '{"anyOf":['
                . '{"type":"array","items":{"type":["string","boolean"]}},'
                . '{"type":"object","properties":{"1":{"type":"string"}},"required":["1"],"additionalProperties":{"type":"boolean"}}'
                . ']}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function anEntryOfAnOpenTupleThatMayBeRemovedBecomesOptional(): void
    {
        $array = $this->intKeyedOpenShape([new IntegerType()->setRequired(true)])->removeItemFromArray(0, isCertain: false);

        $this->assertSame(
            '{"anyOf":['
                . '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"}},'
                . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":"boolean"}}'
                . ']}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function removingAKeyOutsideTheShapeOfAnOpenTupleMayLeaveAGapAndKeepsTheKnownEntries(): void
    {
        $removals = [
            'certain' => $this->intKeyedOpenShape([new IntegerType()->setRequired(true)])->removeItemFromArray(3),
            'uncertain' => $this->intKeyedOpenShape([new IntegerType()->setRequired(true)])->removeItemFromArray(3, isCertain: false),
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($removals),
                '{"anyOf":['
                    . '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"},"minItems":1},'
                    . '{"type":"object","properties":{"0":{"type":"integer"}},"required":["0"],"additionalProperties":{"type":"boolean"}}'
                    . ']}',
            ),
            array_map($this->encodeSchema(...), $removals),
        );
    }


    #[Test]
    public function removingAKeyDropsTheMinimumLengthOfAnArrayWithEntriesOutsideItsShape(): void
    {
        $nonEmpty = fn () => new ArrayType(
            itemType: new BoolType,
            keyType: new IntegerType,
            shape: [new IntegerType],
            minItems: 3,
        );

        $this->assertSame(
            [
                'aKnownKey' => null,
                'aKnownKeyThatMayStay' => null,
                'aKeyOutsideTheShape' => null,
                'everyKeyThatMayGo' => null,
            ],
            [
                'aKnownKey' => $nonEmpty()->removeItemFromArray(0)->minItems,
                'aKnownKeyThatMayStay' => $nonEmpty()->removeItemFromArray(0, isCertain: false)->minItems,
                'aKeyOutsideTheShape' => $nonEmpty()->removeItemFromArray(3)->minItems,
                'everyKeyThatMayGo' => $nonEmpty()->markItemsOptional()->minItems,
            ],
        );
    }


    #[Test]
    public function removingAKeyOfAnOpenShapeThatIsNeverAListKeepsItsKeyOrder(): void
    {
        $neverAList = fn () => $this->intKeyedOpenShape([new IntegerType()->setRequired(true)], IntKeyOrder::NonSequential);

        $this->assertSame(IntKeyOrder::NonSequential, $neverAList()->removeItemFromArray(0)->intKeyOrder);
        $this->assertSame(IntKeyOrder::NonSequential, $neverAList()->removeItemFromArray(3)->intKeyOrder);
    }


    #[Test]
    public function removingAKeyOfAStringKeyedOpenShapeKeepsItAnObject(): void
    {
        $this->assertSame(
            [
                'theKnownKey' => '{"type":"object","additionalProperties":{"type":"integer"}}',
                'theKnownKeyThatMayStay' => '{"type":"object","properties":{"error":{"type":"string"}},"additionalProperties":{"type":"integer"}}',
                'aKeyOutsideTheShape' => '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}}',
            ],
            array_map($this->encodeSchema(...), [
                'theKnownKey' => $this->errorWithIntegerEntries()->removeItemFromArray('error'),
                'theKnownKeyThatMayStay' => $this->errorWithIntegerEntries()->removeItemFromArray('error', isCertain: false),
                'aKeyOutsideTheShape' => $this->errorWithIntegerEntries()->removeItemFromArray('other'),
            ]),
        );
    }


    #[Test]
    public function makingEveryEntryOfAnOpenTupleOptionalMayLeaveGaps(): void
    {
        $array = $this->intKeyedOpenShape([new IntegerType()->setRequired(true)])->markItemsOptional();

        $this->assertSame(
            '{"anyOf":['
                . '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":"boolean"}},'
                . '{"type":"object","properties":{"0":{"type":"integer"}},"additionalProperties":{"type":"boolean"}}'
                . ']}',
            $this->encodeSchema($array),
        );
    }


    #[Test]
    public function aClosedShapeIgnoresTheRemovalOfAKeyItDoesNotList(): void
    {
        $tuple = new ArrayType(shape: [new IntegerType()->setRequired(true), new StringType()->setRequired(true)], minItems: 2);
        $tuple->removeItemFromArray(5);

        $this->assertSame(IntKeyOrder::Sequential, $tuple->intKeyOrder);
        $this->assertSame(2, $tuple->minItems);
        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"},{"type":"string"}],"minItems":2,"maxItems":2}',
            $this->encodeSchema($tuple),
        );
    }


    #[Test]
    public function removingEveryEntryOfAClosedShapeLeavesTheEmptyArray(): void
    {
        $array = new ArrayType(shape: [5 => new IntegerType()->setRequired(true)], intKeyOrder: IntKeyOrder::NonSequential);
        $array->removeItemFromArray(5);

        $this->assertSame([], $array->shape);
        $this->assertFalse($array->hasEntriesOutsideShape());
        $this->assertSame(IntKeyOrder::Sequential, $array->intKeyOrder);
    }


    #[Test]
    public function aUnionOfShapesAllowsTheKeysOfTheOtherEntriesOfEveryMember(): void
    {
        $withIntKeyedBooleans = fn () => new ArrayType(
            itemType: new BoolType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $intKeysFirst = $this->mergedArray(new UnionType([$withIntKeyedBooleans(), $this->errorWithIntegerEntries()]));
        $stringKeysFirst = $this->mergedArray(new UnionType([$this->errorWithIntegerEntries(), $withIntKeyedBooleans()]));

        $this->assertNotNull($intKeysFirst->keyType);
        $this->assertNotNull($stringKeysFirst->keyType);
        $this->assertSame('{"type":["integer","string"]}', $this->encodeSchema($intKeysFirst->keyType));
        $this->assertSame('{"type":["string","integer"]}', $this->encodeSchema($stringKeysFirst->keyType));
    }


    #[Test]
    public function aUnionOfOpenTuplesWithoutKeyTypesKeepsIntKeys(): void
    {
        $openTuple = fn (Type $itemType) => new ArrayType(
            itemType: $itemType,
            shape: [new IntegerType()->setRequired(true)],
        );

        $merged = $this->mergedArray(new UnionType([$openTuple(new BoolType), $openTuple(new StringType)]));

        $this->assertNull($merged->keyType);
        $this->assertSame(
            '{"type":"array","prefixItems":[{"type":"integer"}],"items":{"type":["boolean","string"]},"minItems":1}',
            $this->encodeSchema($merged),
        );
    }


    #[Test]
    public function aUnionWithOtherEntriesOfAnUndeclaredTypeAllowsAnyOtherEntry(): void
    {
        $withEntriesOfAnUndeclaredType = fn () => new ArrayType(
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $unions = [
            'undeclaredFirst' => new UnionType([$withEntriesOfAnUndeclaredType(), $this->errorWithIntegerEntries()]),
            'undeclaredSecond' => new UnionType([$this->errorWithIntegerEntries(), $withEntriesOfAnUndeclaredType()]),
        ];

        $this->assertSame(
            array_fill_keys(
                array_keys($unions),
                '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":true}',
            ),
            array_map($this->encodeSchema(...), $unions),
        );
    }


    #[Test]
    public function anIntersectionOfShapesNarrowsTheKeysAndTypesOfTheirOtherEntries(): void
    {
        $withBooleansUnderAnyKey = fn () => new ArrayType(
            itemType: new BoolType,
            keyType: new UnionType([new IntegerType, new StringType]),
            shape: ['error' => new StringType()->setRequired(true)],
        );
        $withAnyTypeUnderIntKeys = fn () => new ArrayType(
            keyType: new IntegerType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $intersections = [
            'typedFirst' => new IntersectionType([$withBooleansUnderAnyKey(), $withAnyTypeUnderIntKeys()]),
            'typedSecond' => new IntersectionType([$withAnyTypeUnderIntKeys(), $withBooleansUnderAnyKey()]),
        ];

        foreach ($intersections as $intersection) {
            $merged = $this->mergedArray($intersection);

            $this->assertInstanceOf(IntegerType::class, $merged->keyType);
            $this->assertInstanceOf(BoolType::class, $merged->itemType);
        }
    }


    #[Test]
    public function anIntersectionOfShapesWhoseOtherEntriesExcludeEachOtherLeavesAClosedShape(): void
    {
        $errorWith = fn (Type $itemType, ?Type $keyType) => new ArrayType(
            itemType: $itemType,
            keyType: $keyType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $idBefore = fn (Type $itemType) => new ArrayType(
            itemType: $itemType,
            keyType: new IntegerType,
            shape: [new IntegerType()->setRequired(true)],
        );

        $merged = array_map($this->mergedArray(...), [
            'valueTypes' => new IntersectionType([$this->errorWithIntegerEntries(), $errorWith(new BoolType, new StringType)]),
            'keyTypes' => new IntersectionType([$this->errorWithIntegerEntries(), $errorWith(new IntegerType, new IntegerType)]),
            'keyTypesOfAMemberWithoutOne' => new IntersectionType([$this->errorWithIntegerEntries(), $errorWith(new IntegerType, null)]),
            'valueTypesOfTuples' => new IntersectionType([$idBefore(new IntegerType), $idBefore(new BoolType)]),
        ]);

        $errorOnly = '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"]}';
        $errorOnlyInTypeScript = implode("\n", [
            '{',
            '    error: string',
            '}',
        ]);

        $this->assertSame(
            array_fill_keys(array_keys($merged), false),
            array_map(fn (ArrayType $array) => $array->hasEntriesOutsideShape(), $merged),
        );
        $this->assertSame(
            [
                'valueTypes' => $errorOnly,
                'keyTypes' => $errorOnly,
                'keyTypesOfAMemberWithoutOne' => $errorOnly,
                'valueTypesOfTuples' => '{"type":"array","prefixItems":[{"type":"integer"}],"minItems":1,"maxItems":1}',
            ],
            array_map($this->encodeSchema(...), $merged),
        );
        $this->assertSame(
            [
                'valueTypes' => $errorOnlyInTypeScript,
                'keyTypes' => $errorOnlyInTypeScript,
                'keyTypesOfAMemberWithoutOne' => $errorOnlyInTypeScript,
                'valueTypesOfTuples' => '[number]',
            ],
            array_map(fn (ArrayType $array) => $this->toTypeScript($array), $merged),
        );
    }


    #[Test]
    public function anIntersectionOfShapesWithDifferentKeysKeepsTheTypeOfEachKeyThatTheOtherEntriesAllow(): void
    {
        $errorWithIntegersOrStrings = new ArrayType(
            itemType: new UnionType([new IntegerType, new StringType]),
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true)],
        );
        $codeWithIntegersOrStrings = new ArrayType(
            itemType: new UnionType([new IntegerType, new StringType]),
            keyType: new StringType,
            shape: ['code' => new IntegerType()->setRequired(true)],
        );

        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error","code"],"additionalProperties":{"type":["integer","string"]}}',
            $this->encodeSchema(new IntersectionType([$errorWithIntegersOrStrings, $codeWithIntegersOrStrings])),
        );
    }


    #[Test]
    public function anIntersectionWithAClosedShapeKeepsTheOtherEntriesOfTheOtherMember(): void
    {
        $code = fn () => new ArrayType(shape: ['code' => new IntegerType()->setRequired(true)]);

        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"},"code":{"type":"integer"}},"required":["error","code"],"additionalProperties":{"type":"integer"}}',
            $this->encodeSchema(new IntersectionType([$this->errorWithIntegerEntries(), $code()])),
        );
        $this->assertSame(
            '{"type":"object","properties":{"code":{"type":"integer"},"error":{"type":"string"}},"required":["code","error"],"additionalProperties":{"type":"integer"}}',
            $this->encodeSchema(new IntersectionType([$code(), $this->errorWithIntegerEntries()])),
        );
    }


    #[Test]
    public function aShapeIntersectedWithAMapStaysASeparateMember(): void
    {
        $integersByName = new ArrayType(itemType: new IntegerType, keyType: new StringType);

        $this->assertSame(
            '{"allOf":['
                . '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}},'
                . '{"type":"object","additionalProperties":{"type":"integer"}}'
                . ']}',
            $this->encodeSchema(new IntersectionType([$this->errorWithIntegerEntries(), $integersByName])),
        );
    }


    #[Test]
    public function mergingShapesLeavesTheOtherEntriesOfEachMemberUntouched(): void
    {
        $withIntegers = $this->errorWithIntegerEntries();
        $withIntegersOrStrings = new ArrayType(
            itemType: new UnionType([new IntegerType, new StringType]),
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $this->mergedArray(new UnionType([$withIntegers, $withIntegersOrStrings]));
        $this->mergedArray(new UnionType([$withIntegersOrStrings, $withIntegers]));
        $this->mergedArray(new IntersectionType([$withIntegers, $withIntegersOrStrings]));
        $this->mergedArray(new IntersectionType([$withIntegersOrStrings, $withIntegers]));

        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":"integer"}}',
            $this->encodeSchema($withIntegers),
        );
        $this->assertSame(
            '{"type":"object","properties":{"error":{"type":"string"}},"required":["error"],"additionalProperties":{"type":["integer","string"]}}',
            $this->encodeSchema($withIntegersOrStrings),
        );
    }


    #[Test]
    public function aKeyThatOnlyOneShapeListsIsNarrowedByTheOtherEntriesOfTheOtherShape(): void
    {
        $errorAndCount = fn (Type $countType) => new ArrayType(shape: [
            'error' => new StringType()->setRequired(true),
            'count' => $countType,
        ]);

        $merged = array_map($this->mergedArray(...), [
            'requiredKey' => new IntersectionType([$errorAndCount(new UnionType([new IntegerType, new StringType])->setRequired(true)), $this->errorWithIntegerEntries()]),
            'requiredKeyOfTheSecondMember' => new IntersectionType([$this->errorWithIntegerEntries(), $errorAndCount(new UnionType([new IntegerType, new StringType])->setRequired(true))]),
        ]);

        $this->assertSame(
            array_fill_keys(
                array_keys($merged),
                '{"type":"object","properties":{"error":{"type":"string"},"count":{"type":"integer"}},"required":["error","count"],"additionalProperties":{"type":"integer"}}',
            ),
            array_map($this->encodeSchema(...), $merged),
        );
    }


    #[Test]
    public function anOptionalKeyThatTheOtherEntriesOfTheOtherShapeCanNotHoldStaysAsAConflict(): void
    {
        $errorAndCount = new ArrayType(shape: [
            'error' => new StringType()->setRequired(true),
            'count' => new BoolType,
        ]);

        $merged = array_map($this->mergedArray(...), [
            'firstMember' => new IntersectionType([clone $errorAndCount, $this->errorWithIntegerEntries()]),
            'secondMember' => new IntersectionType([$this->errorWithIntegerEntries(), clone $errorAndCount]),
        ]);

        $config = self::loadConfig();

        $this->assertSame(
            array_fill_keys(array_keys($merged), [NeverType::class, false, false]),
            array_map(
                fn (ArrayType $array) => [
                    $array->shape['count']->unwrapType($config)::class,
                    $array->shape['count']->required,
                    $array->hasRequiredEntryThatCanNotExist($config),
                ],
                $merged,
            ),
        );
    }


    #[Test]
    public function aRequiredKeyThatTheOtherEntriesOfTheOtherShapeCanNotHoldCanNotExist(): void
    {
        $errorAnd = fn (int|string $key, Type $valueType) => new ArrayType(shape: [
            'error' => new StringType()->setRequired(true),
            $key => $valueType->setRequired(true),
        ]);

        $errorWithIntKeyedIntegers = new ArrayType(
            itemType: new IntegerType,
            keyType: new IntegerType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $merged = array_map($this->mergedArray(...), [
            'valueType' => new IntersectionType([$errorAnd('name', new StringType('y')), $this->errorWithIntegerEntries()]),
            'valueTypeOfTheSecondMember' => new IntersectionType([$this->errorWithIntegerEntries(), $errorAnd('name', new StringType('y'))]),
            'intKeyAgainstStringKeys' => new IntersectionType([$errorAnd(5, new IntegerType), $this->errorWithIntegerEntries()]),
            'stringKeyAgainstIntKeys' => new IntersectionType([$errorAnd('count', new IntegerType), $errorWithIntKeyedIntegers]),
            'keyBothShapesList' => new IntersectionType([$errorAnd('count', new IntegerType), $errorAnd('count', new StringType)]),
            'fittingKey' => new IntersectionType([$errorAnd('count', new IntegerType), $this->errorWithIntegerEntries()]),
        ]);

        $this->assertSame(
            [
                'valueType' => true,
                'valueTypeOfTheSecondMember' => true,
                'intKeyAgainstStringKeys' => true,
                'stringKeyAgainstIntKeys' => true,
                'keyBothShapesList' => true,
                'fittingKey' => false,
            ],
            array_map(fn (ArrayType $array) => $array->hasRequiredEntryThatCanNotExist(self::loadConfig()), $merged),
        );
    }


    #[Test]
    public function aKeyThatOnlyOneShapeListsKeepsItsTypeNextToAShapeThatAllowsIt(): void
    {
        $errorAndName = fn () => new ArrayType(shape: [
            'error' => new StringType()->setRequired(true),
            'name' => new StringType()->setRequired(true),
        ]);

        $errorWithUnknownEntries = new ArrayType(
            itemType: new UnknownType,
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true)],
        );

        $merged = array_map($this->mergedArray(...), [
            'closedShape' => new IntersectionType([$errorAndName(), new ArrayType(shape: ['error' => new StringType()->setRequired(true)])]),
            'unknownOtherEntries' => new IntersectionType([$errorAndName(), $errorWithUnknownEntries]),
        ]);

        $this->assertSame(
            [
                'closedShape' => '{"type":"object","properties":{"error":{"type":"string"},"name":{"type":"string"}},"required":["error","name"]}',
                'unknownOtherEntries' => '{"type":"object","properties":{"error":{"type":"string"},"name":{"type":"string"}},"required":["error","name"],"additionalProperties":true}',
            ],
            array_map($this->encodeSchema(...), $merged),
        );
    }


    private function mergedArray(UnionType|IntersectionType $type): ArrayType
    {
        $merged = $type->unwrapType(self::loadConfig());

        $this->assertInstanceOf(ArrayType::class, $merged);

        return $merged;
    }


    /**
     * @param array<int|string, Type> $shape
     */
    private function intKeyedOpenShape(array $shape, IntKeyOrder $intKeyOrder = IntKeyOrder::Sequential): ArrayType
    {
        return new ArrayType(
            itemType: new BoolType,
            keyType: new IntegerType,
            shape: $shape,
            intKeyOrder: $intKeyOrder,
        );
    }


    private function errorWithIntegerEntries(): ArrayType
    {
        return new ArrayType(
            itemType: new IntegerType,
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true)],
        );
    }


    private function errorAndOptionalDetailsWithIntegerEntries(): ArrayType
    {
        return new ArrayType(
            itemType: new IntegerType,
            keyType: new StringType,
            shape: ['error' => new StringType()->setRequired(true), 'details' => new StringType],
        );
    }


    private function encodeSchema(Type $type): string
    {
        $config = self::loadConfig();

        return json_encode(SchemaJson::castPropertiesToObjects($type->unwrapType($config)->toSchema($config)), JSON_THROW_ON_ERROR);
    }


    private function toTypeScript(Type $type, ?Config $config = null): string
    {
        $config ??= self::loadConfig();

        return new TypeConverter()->convertToTypeScriptType($type, new TypeScriptRenderContext(new Scope($config), $config->getTypeScriptConfig()));
    }


    /**
     * @param array{
     *     omit?: string[],
     *     only?: string[],
     *     with?: array<int|string, Type>,
     * } $rootOptions
     */
    private function toRootTypeScript(Type $type, array $rootOptions): string
    {
        $config = self::loadConfig();

        $context = new TypeScriptRenderContext(
            scope: new Scope($config),
            config: $config->getTypeScriptConfig(),
            isRootLevel: true,
            rootOptions: $rootOptions,
        );

        return new TypeConverter()->convertToTypeScriptType($type, $context);
    }
}
