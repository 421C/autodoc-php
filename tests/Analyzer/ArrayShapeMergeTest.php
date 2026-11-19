<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use stdClass;

final class ArrayShapeMergeTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function anOptionalSpreadKeyKeepsAnEarlierRequiredKey(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            /** @var array{timezone?: string} */
            $overrides = [];

            return ['timezone' => 'UTC', 'locale' => 'en', ...$overrides];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'timezone' => [
                    'type' => 'string',
                ],
                'locale' => [
                    'type' => 'string',
                    'const' => 'en',
                ],
            ],
            'required' => [
                'timezone',
                'locale',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anOptionalSpreadKeyKeepsAKeyRequiredByAnEarlierSpread(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $defaults = ['theme' => 'dark'];

            /** @var array{theme?: string} */
            $overrides = [];

            return [...$defaults, ...$overrides];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'theme' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'theme',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anOptionalArrayMergeKeyKeepsAnEarlierRequiredKey(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            /** @var array{timezone?: string} */
            $overrides = [];

            return array_merge(['timezone' => 'UTC'], $overrides);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'timezone' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'timezone',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anOptionalSpreadKeyOfAnotherTypeWidensTheEarlierValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            /** @var array{a?: string} */
            $overrides = [];

            return ['a' => 1, ...$overrides];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'a' => [
                    'anyOf' => [
                        [
                            'type' => 'integer',
                            'const' => 1,
                        ],
                        [
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
            'required' => [
                'a',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anOptionalSpreadKeyOverAnOptionalKeyStaysOptional(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            /** @var array{a?: int} */
            $defaults = [];

            /** @var array{a?: string} */
            $overrides = [];

            return [...$defaults, ...$overrides];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'a' => [
                    'type' => [
                        'integer',
                        'string',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aRequiredSpreadKeyReplacesTheEarlierValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $overrides = ['a' => 'x'];

            return ['a' => 1, ...$overrides];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'a' => [
                    'type' => 'string',
                    'const' => 'x',
                ],
            ],
            'required' => [
                'a',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function spreadIntKeysContinueAfterTheLiteralsOwnIntKeys(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            /** @var array{int, string} */
            $tuple = [1, 'b'];

            return [5 => 'a', ...$tuple];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                '5' => [
                    'type' => 'string',
                    'const' => 'a',
                ],
                '6' => [
                    'type' => 'integer',
                ],
                '7' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                '5',
                '6',
                '7',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function arrayMergeRenumbersIntKeys(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            return array_merge([5 => 'a'], ['x' => 1]);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                '0' => [
                    'type' => 'string',
                    'const' => 'a',
                ],
                'x' => [
                    'type' => 'integer',
                    'const' => 1,
                ],
            ],
            'required' => [
                '0',
                'x',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aKeylessItemAfterASpreadTakesTheNextIndex(): void
    {
        $schema = $this->getClosureReturnSchema(
            closure: function (): mixed {
                /** @var array{int, string} */
                $tuple = [1, 'b'];

                return [3 => 'a', ...$tuple, 'c'];
            },
            resolvePartialShapes: true,
        );

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                '3' => [
                    'type' => 'string',
                    'const' => 'a',
                ],
                '4' => [
                    'type' => 'integer',
                ],
                '5' => [
                    'type' => 'string',
                ],
                '6' => [
                    'type' => 'string',
                    'const' => 'c',
                ],
            ],
            'required' => [
                '3',
                '4',
                '5',
                '6',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aPartialShapeWithAnUnknownKeyKeepsItsEntries(): void
    {
        $stringListOrMap = [
            'anyOf' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ];

        $closures = [
            'intKey' => [
                fn (int $id, string $name): mixed => [$id => $name],
                $stringListOrMap,
            ],
            'intKeyAfterAKnownKey' => [
                fn (int $id, string $first, string $second): mixed => [0 => $first, $id => $second],
                $stringListOrMap,
            ],
            'intKeyAfterAKeylessItem' => [
                fn (int $id, string $first, string $second): mixed => [$first, $id => $second],
                $stringListOrMap,
            ],
            'stringKeyAfterAKnownKey' => [
                fn (string $key, string $first, string $second): mixed => ['id' => $first, $key => $second],
                [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ];

        foreach ($closures as $name => [$closure, $expectedSchema]) {
            $schema = $this->getClosureReturnSchema($closure, resolvePartialShapes: true);

            $this->assertSchemaArraysMatch($expectedSchema, $schema, $name, 'return');
        }
    }


    #[Test]
    public function aKeylessItemAfterASpreadWithAMissingEntryMayTakeAnEarlierIndex(): void
    {
        $schema = $this->getClosureReturnSchema(
            closure: function (): mixed {
                /** @var array{int, ?string} */
                $pair = [1, null];

                return [...array_filter($pair), 'c'];
            },
            resolvePartialShapes: true,
        );

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'prefixItems' => [
                [
                    'type' => [
                        'integer',
                        'string',
                    ],
                ],
                [
                    'type' => 'string',
                ],
                [
                    'type' => 'string',
                    'const' => 'c',
                ],
            ],
            'minItems' => 1,
            'maxItems' => 3,
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aKeyWithSeveralPossibleValuesKeepsTheLiteralsIntKeys(): void
    {
        $schema = $this->getClosureReturnSchema(function (bool $flag): mixed {
            $key = $flag ? 'x' : 'y';

            return [5 => 'a', $key => 'b'];
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        '5' => [
                            'type' => 'string',
                            'const' => 'a',
                        ],
                        'x' => [
                            'type' => 'string',
                            'const' => 'b',
                        ],
                    ],
                    'required' => [
                        '5',
                        'x',
                    ],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        '5' => [
                            'type' => 'string',
                            'const' => 'a',
                        ],
                        'y' => [
                            'type' => 'string',
                            'const' => 'b',
                        ],
                    ],
                    'required' => [
                        '5',
                        'y',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function spreadingAUnionKeepsKeysFoundInEveryMemberRequired(): void
    {
        $schema = $this->getClosureReturnSchema(function (bool $flag): mixed {
            return [...($flag ? ['a' => 1] : ['a' => 2, 'b' => 3])];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'a' => [
                    'type' => 'integer',
                    'enum' => [
                        1,
                        2,
                    ],
                ],
                'b' => [
                    'type' => 'integer',
                    'const' => 3,
                ],
            ],
            'required' => [
                'a',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function spreadingAUnionLeavesTheOperandUnchanged(): void
    {
        $schema = $this->getClosureReturnSchema(function (bool $flag): mixed {
            $parts = $flag ? ['a' => 1] : ['b' => 2];

            return ['spread' => [...$parts], 'parts' => $parts];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'spread' => [
                    'type' => 'object',
                    'properties' => [
                        'a' => [
                            'type' => 'integer',
                            'const' => 1,
                        ],
                        'b' => [
                            'type' => 'integer',
                            'const' => 2,
                        ],
                    ],
                ],
                'parts' => [
                    'anyOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'a' => [
                                    'type' => 'integer',
                                    'const' => 1,
                                ],
                            ],
                            'required' => [
                                'a',
                            ],
                        ],
                        [
                            'type' => 'object',
                            'properties' => [
                                'b' => [
                                    'type' => 'integer',
                                    'const' => 2,
                                ],
                            ],
                            'required' => [
                                'b',
                            ],
                        ],
                    ],
                ],
            ],
            'required' => [
                'spread',
                'parts',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function spreadingAListOfShapesKeepsItAList(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $rows = array_map(fn ($row) => $row, [['id' => 1], 'x']);

            return [...$rows];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'items' => [
                'anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => [
                            'id' => [
                                'type' => 'integer',
                                'const' => 1,
                            ],
                        ],
                        'required' => [
                            'id',
                        ],
                    ],
                    [
                        'type' => 'string',
                        'const' => 'x',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aSpreadShapeAddsItsValuesToTheItemTypeWhenAKeyIsUnknown(): void
    {
        $schema = $this->getClosureReturnSchema(function (string $key): mixed {
            return [...['a' => 1], $key => 'x'];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'additionalProperties' => [
                'anyOf' => [
                    [
                        'type' => 'integer',
                        'const' => 1,
                    ],
                    [
                        'type' => 'string',
                        'const' => 'x',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anEntryMergedFromBranchesKeepsRequiredOffItsMembers(): void
    {
        $config = self::loadConfig();

        $service = $this->getClosureReturnType($config, function (bool $flag): mixed {
            $service = ArrayShapeMergeTest::service();

            if ($flag) {
                $service['displayName'] = 'x';
            }

            return $service;
        });

        $entity = $this->getClosureReturnType($config, function (bool $hasDate, bool $flag): mixed {
            $entity = new stdClass;

            if ($hasDate) {
                $entity->date = null;
            }

            if ($flag) {
                $entity->date = 'x';
            }

            return $entity;
        });

        self::assertInstanceOf(ArrayType::class, $service);
        self::assertInstanceOf(ObjectType::class, $entity);

        self::assertSame(
            [
                'shapeEntry' => ['entry' => false, 'members' => [false, false]],
                'objectProperty' => ['entry' => false, 'members' => [false, false]],
            ],
            [
                'shapeEntry' => $this->getRequiredFlags($service->shape['displayName'], $config),
                'objectProperty' => $this->getRequiredFlags($entity->properties['date'], $config),
            ],
        );
    }


    /**
     * @return array{entry: bool, members: list<bool>}
     */
    private function getRequiredFlags(Type $entry, Config $config): array
    {
        $entry = $entry->unwrapType($config);
        $members = $entry instanceof UnionType ? array_values($entry->types) : [];

        return [
            'entry' => $entry->required,
            'members' => array_map(fn (Type $member) => $member->required, $members),
        ];
    }


    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure, bool $resolvePartialShapes = false): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;
        $config->data['arrays']['resolve_partial_shapes'] = $resolvePartialShapes;

        return $this->getClosureReturnType($config, $closure)->toSchema($config);
    }


    private function getClosureReturnType(Config $config, Closure $closure): Type
    {
        return new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType()->unwrapType($config);
    }


    /**
     * @return array{code: string, displayName?: string|null}
     */
    private static function service(): array
    {
        /** @phpstan-ignore return.type */
        return json_decode('[]', true);
    }
}
