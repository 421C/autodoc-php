<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
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
