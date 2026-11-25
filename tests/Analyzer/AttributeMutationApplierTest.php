<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Flow\AttributeMutationApplier;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttributeMutationApplierTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function certainMutationDoesNotMutateTheAttributeInPlace(): void
    {
        $config = self::loadConfig();
        $applier = new AttributeMutationApplier(new Scope($config));

        $nested = new ObjectType(properties: ['x' => new StringType]);
        $attributes = ['meta' => $nested];

        $applier->apply(new ObjectType(properties: []), [], $attributes, isCertain: true);

        self::assertFalse(
            $nested->properties['x']->required,
            'the passed attribute Type must not be mutated in place',
        );
    }

    #[Test]
    public function certainDynamicMutationDoesNotMutateTheDynamicAttributeInPlace(): void
    {
        $config = self::loadConfig();
        $applier = new AttributeMutationApplier(new Scope($config));

        $dynamicAttribute = new StringType('x');

        $result = $applier->apply(
            baseType: new ArrayType,
            mutationPath: [],
            attributes: [],
            isCertain: true,
            dynamicAttribute: $dynamicAttribute,
        );

        self::assertFalse(
            $dynamicAttribute->required,
            'the passed dynamic attribute Type must not be mutated in place',
        );
        self::assertInstanceOf(ArrayType::class, $result);
        self::assertNotSame($dynamicAttribute, $result->itemType);
    }

    #[Test]
    public function keyedMutationOnGenericArrayKeepsItAnArray(): void
    {
        $config = self::loadConfig();
        $applier = new AttributeMutationApplier(new Scope($config));

        $element = new ObjectType(properties: ['id' => (new IntegerType)->setRequired(true)]);
        $array = new ArrayType(itemType: $element);

        $result = $applier->apply($array, [0], ['tagged' => new StringType], isCertain: false);

        self::assertInstanceOf(ArrayType::class, $result);
        self::assertSame('array', $result->toSchema($config)['type'] ?? null);
    }

    #[Test]
    public function keyedMutationOnPartialShapeArrayLandsInTheShape(): void
    {
        $config = self::loadConfig();
        $applier = new AttributeMutationApplier(new Scope($config));

        $element = new ObjectType(properties: ['id' => (new IntegerType)->setRequired(true)]);
        $array = new ArrayType(
            shape: ['a' => (clone $element)->setRequired(true)],
            itemType: $element,
            keyType: new StringType,
        );

        $result = $applier->apply($array, ['b'], ['tagged' => new StringType], isCertain: false);

        self::assertInstanceOf(ArrayType::class, $result);
        self::assertArrayHasKey('b', $result->shape);

        $mutatedElement = $result->shape['b']->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $mutatedElement);
        self::assertArrayHasKey('tagged', $mutatedElement->properties);
    }

    #[Test]
    public function certainMutationReplacesTheArrayOrObjectAtItsKey(): void
    {
        $config = self::loadConfig();
        $applier = new AttributeMutationApplier(new Scope($config));

        $result = $applier->apply(
            baseType: new ObjectType(properties: [
                'meta' => new ObjectType(properties: ['before' => new StringType]),
                'tags' => new ArrayType(shape: ['before' => new StringType]),
            ]),
            mutationPath: [],
            attributes: [
                'meta' => new ObjectType(properties: ['after' => new IntegerType]),
                'tags' => new ArrayType(shape: ['after' => new IntegerType]),
            ],
            isCertain: true,
        );

        self::assertInstanceOf(ObjectType::class, $result);

        $meta = $result->properties['meta']->unwrapType($config);
        $tags = $result->properties['tags']->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $meta);
        self::assertInstanceOf(ArrayType::class, $tags);
        self::assertSame(['after'], array_keys($meta->properties));
        self::assertSame(['after'], array_keys($tags->shape));
    }

    #[Test]
    public function certainMutationKeepsOptionalEntriesOfTheWrittenValueOptional(): void
    {
        $config = self::loadConfig();
        $applier = new AttributeMutationApplier(new Scope($config));

        $result = $applier->apply(
            baseType: new ObjectType(properties: []),
            mutationPath: [],
            attributes: [
                'meta' => new ObjectType(properties: [
                    'id' => (new IntegerType)->setRequired(true),
                    'note' => new StringType,
                ]),
                'row' => new ArrayType(shape: [
                    'a' => (new IntegerType)->setRequired(true),
                    'b' => new IntegerType,
                ]),
            ],
            isCertain: true,
        );

        self::assertInstanceOf(ObjectType::class, $result);
        self::assertTrue($result->properties['meta']->required);
        self::assertTrue($result->properties['row']->required);

        $meta = $result->properties['meta']->unwrapType($config);
        $row = $result->properties['row']->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $meta);
        self::assertInstanceOf(ArrayType::class, $row);
        self::assertTrue($meta->properties['id']->required);
        self::assertFalse($meta->properties['note']->required);
        self::assertTrue($row->shape['a']->required);
        self::assertFalse($row->shape['b']->required);
    }
}
