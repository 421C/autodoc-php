<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnresolvedType;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeepResolveTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function resolverResultsAreDeepCloned(): void
    {
        $sharedProperty = new StringType;
        $sharedType = new ObjectType(properties: ['value' => $sharedProperty]);
        $type = new ObjectType(properties: [
            'payload' => new DeepResolveTypeResolver($sharedType),
        ]);

        $resolvedType = $type->deepResolve(self::loadConfig());

        self::assertInstanceOf(ObjectType::class, $resolvedType);
        $resolvedPayload = $resolvedType->properties['payload'];
        self::assertInstanceOf(ObjectType::class, $resolvedPayload);
        self::assertNotSame($sharedType, $resolvedPayload);
        self::assertNotSame($sharedProperty, $resolvedPayload->properties['value']);
    }

    #[Test]
    public function resolverResultsAreClonedBeforeUnwrapping(): void
    {
        $sharedType = new UnionType([
            new StringType('first'),
            new StringType('second'),
        ]);
        $type = new ObjectType(properties: [
            'value' => new DeepResolveTypeResolver($sharedType),
        ]);

        $type->deepResolve(self::loadConfig());

        self::assertCount(2, $sharedType->types);
    }

    #[Test]
    public function unresolvedPropertyOwnsRequiredness(): void
    {
        $type = new ObjectType(properties: [
            'value' => new DeepResolveTypeResolver(
                resolvedType: (new StringType)->setRequired(true),
            ),
        ]);

        $resolvedType = $type->deepResolve(self::loadConfig());

        self::assertInstanceOf(ObjectType::class, $resolvedType);
        self::assertFalse($resolvedType->properties['value']->required);
    }

    #[Test]
    public function unresolvedUnionMembersCombineRequiredness(): void
    {
        $type = new UnionType([
            new DeepResolveTypeResolver(
                resolvedType: (new StringType)->setRequired(true),
            ),
            (new DeepResolveTypeResolver(
                resolvedType: new StringType,
            ))->setRequired(true),
        ]);

        $resolvedType = $type->deepResolve(self::loadConfig());

        self::assertInstanceOf(UnionType::class, $resolvedType);
        self::assertTrue($resolvedType->types[0]->required);
        self::assertTrue($resolvedType->types[1]->required);
    }

    #[Test]
    public function unresolvedRootCombinesRequiredness(): void
    {
        $type = new DeepResolveTypeResolver(
            resolvedType: (new StringType)->setRequired(true),
        );

        $resolvedType = $type->deepResolve(self::loadConfig());

        self::assertTrue($resolvedType->required);
    }
}

final class DeepResolveTypeResolver extends UnresolvedType
{
    public function __construct(
        private readonly Type $resolvedType,
    ) {}

    public function resolve(): Type
    {
        return $this->resolvedType;
    }
}
