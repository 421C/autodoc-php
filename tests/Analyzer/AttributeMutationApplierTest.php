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
        );

        $result = $applier->apply($array, ['b'], ['tagged' => new StringType], isCertain: false);

        self::assertInstanceOf(ArrayType::class, $result);
        self::assertArrayHasKey('b', $result->shape);

        $mutatedElement = $result->shape['b']->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $mutatedElement);
        self::assertArrayHasKey('tagged', $mutatedElement->properties);
    }
}
