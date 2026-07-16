<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A union whose duplicate members merge down to a single member must collapse
 * onto that member within one unwrapType() call — mirroring the intersection
 * branch — so callers don't have to unwrap a second time.
 */
final class UnionTypeCollapseTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function duplicateMembersCollapseToTheMergedMemberInOneUnwrap(): void
    {
        $config = self::loadConfig();

        $type = (new UnionType([
            new ArrayType(shape: ['id' => (new IntegerType)->setRequired(true)]),
            new ArrayType(shape: ['id' => (new IntegerType)->setRequired(true)]),
        ]))->unwrapType($config);

        self::assertInstanceOf(ArrayType::class, $type);
        self::assertArrayHasKey('id', $type->shape);
    }

    #[Test]
    public function collapsedUnionCarriesItsMetadataOntoTheMember(): void
    {
        $config = self::loadConfig();

        $union = new UnionType([
            new ArrayType(shape: ['id' => (new IntegerType)->setRequired(true)]),
            new ArrayType(shape: ['id' => (new IntegerType)->setRequired(true)]),
        ]);
        $union->description = 'The record.';
        $union->required = true;

        $type = $union->unwrapType($config);

        self::assertInstanceOf(ArrayType::class, $type);
        self::assertSame('The record.', $type->description);
        self::assertTrue($type->required);
    }

    #[Test]
    public function collapseDoesNotMutateAMemberSharedWithAnotherWrapper(): void
    {
        $config = self::loadConfig();

        $member = new IntegerType;
        $member->description = 'Base.';

        $wrapper = new UnionType([$member]);
        $wrapper->description = 'Wrapper.';
        $wrapper->required = true;

        $wrapper->unwrapType($config);

        self::assertSame('Base.', $member->description);
        self::assertFalse($member->required);
    }

    #[Test]
    public function nestedCollapsesPreserveMetadataAndRemainIdempotent(): void
    {
        $config = self::loadConfig();

        $firstMember = new ArrayType(shape: ['id' => (new IntegerType)->setRequired(true)]);
        $firstMember->description = 'Member.';

        $secondMember = new ArrayType(shape: ['id' => (new IntegerType)->setRequired(true)]);
        $secondMember->description = 'Member.';

        $innerUnion = new UnionType([$firstMember, $secondMember]);
        $innerUnion->description = 'Inner wrapper.';
        $innerUnion->example = 'inner';

        $outerUnion = new UnionType([$innerUnion]);
        $outerUnion->description = 'Outer wrapper.';
        $outerUnion->examples = ['outer'];
        $outerUnion->example = '';
        $outerUnion->required = true;
        $outerUnion->deprecated = true;

        $type = $outerUnion->unwrapType($config);

        self::assertEquals($type, $outerUnion->unwrapType($config));
        self::assertInstanceOf(ArrayType::class, $type);
        self::assertSame(
            'Member.' . chr(10) . chr(10) . 'Inner wrapper.' . chr(10) . chr(10) . 'Outer wrapper.',
            $type->description,
        );
        self::assertSame(['outer'], $type->examples);
        self::assertTrue($type->required);
        self::assertTrue($type->deprecated);
        self::assertSame('', $type->example);
    }
}
