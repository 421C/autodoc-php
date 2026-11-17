<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\IntKeyOrder;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NeverType;
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
