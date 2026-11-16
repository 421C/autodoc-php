<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\OpenApi\Parameter;
use AutoDoc\OpenApi\SchemaJson;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaJsonTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function propertiesInNestedSchemaPositionsEncodeAsObjects(): void
    {
        $schema = [
            'anyOf' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            ['type' => 'integer'],
                        ],
                    ],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                ['type' => 'string'],
                            ],
                        ],
                    ],
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            '{"anyOf":['
                . '{"type":"array","items":{"type":"object","properties":{"0":{"type":"integer"}}}},'
                . '{"type":"object","properties":{"0":{"type":"object","properties":{"0":{"type":"string"}}}},'
                . '"additionalProperties":{"type":"object","properties":{"0":{"type":"boolean"}}}}'
                . ']}',
            json_encode(SchemaJson::castPropertiesToObjects($schema), JSON_THROW_ON_ERROR),
        );
    }


    #[Test]
    public function exampleValuesAreLeftAsIs(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array'],
            ],
            'examples' => [
                ['properties' => ['a', 'b']],
            ],
            'default' => ['properties' => ['a']],
        ];

        $this->assertSame(
            '{"type":"object","properties":{"tags":{"type":"array"}},"examples":[{"properties":["a","b"]}],"default":{"properties":["a"]}}',
            json_encode(SchemaJson::castPropertiesToObjects($schema), JSON_THROW_ON_ERROR),
        );
    }


    #[Test]
    public function propertiesNamedLikeDataKeywordsAreStillWalked(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'examples' => [
                    'type' => 'object',
                    'properties' => [
                        ['type' => 'integer'],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            '{"type":"object","properties":{"examples":{"type":"object","properties":{"0":{"type":"integer"}}}}}',
            json_encode(SchemaJson::castPropertiesToObjects($schema), JSON_THROW_ON_ERROR),
        );
    }


    #[Test]
    public function subschemasUnderAnyKeywordAreWalked(): void
    {
        $schema = [
            'not' => [
                'type' => 'object',
                'properties' => [
                    ['type' => 'integer'],
                ],
            ],
            'oneOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            '{"not":{"type":"object","properties":{"0":{"type":"integer"}}},"oneOf":[{"type":"object","properties":{"0":{"type":"string"}}}]}',
            json_encode(SchemaJson::castPropertiesToObjects($schema), JSON_THROW_ON_ERROR),
        );
    }


    #[Test]
    public function aParameterSchemaEncodesPropertiesAsAnObject(): void
    {
        $parameter = new Parameter(
            name: 'pair',
            in: 'query',
            config: self::loadConfig(),
            type: new ArrayType(shape: [
                new IntegerType,
                new StringType()->setRequired(true),
            ]),
        );

        $this->assertSame(
            '{"name":"pair","in":"query","schema":{"type":"object","properties":{"0":{"type":"integer"},"1":{"type":"string"}},"required":["1"]}}',
            json_encode($parameter, JSON_THROW_ON_ERROR),
        );
    }
}
