<?php declare(strict_types=1);

namespace AutoDoc\Tests\DataTypes;

use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnionSchemaTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aUnionRenderedAsATypeListKeepsItsMetadata(): void
    {
        $union = new UnionType([new IntegerType, new StringType]);
        $union->description = 'The ID.';
        $union->examples = [5, 'abc'];
        $union->deprecated = true;
        $union->deprecatedDescription = 'Use the UUID.';

        $this->assertSame(
            [
                'type' => ['integer', 'string'],
                'description' => 'The ID.',
                'examples' => [5, 'abc'],
                'deprecated' => true,
                'x-deprecated-description' => 'Use the UUID.',
            ],
            $union->toSchema(self::loadConfig()),
        );
    }


    #[Test]
    public function aUnionRenderedAsAnyOfKeepsItsMetadata(): void
    {
        $union = new UnionType([
            new IntegerType(minimum: 1),
            new StringType(format: 'uuid'),
            new NullType,
        ]);
        $union->description = 'The ID.';
        $union->examples = [5];

        $this->assertSame(
            [
                'anyOf' => [
                    ['type' => 'integer', 'minimum' => 1],
                    ['type' => 'string', 'format' => 'uuid'],
                    ['type' => 'null'],
                ],
                'description' => 'The ID.',
                'examples' => [5],
            ],
            $union->toSchema(self::loadConfig()),
        );
    }
}
