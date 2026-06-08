<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\NestedPropertyRoot;
use AutoDoc\Tests\TestProject\Entities\PermissionEnum;
use AutoDoc\Tests\TestProject\Entities\Rocket;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\TestProject\Entities\StateEnum;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class ControlFlowAnalysisTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function assignmentInsideIfConditionRemainsVisibleAfterTheIf(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $item = null;

            if (($item = ['name' => 'Ada']) !== null) { // @phpstan-ignore notIdentical.alwaysTrue
            }

            return $item;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'const' => 'Ada',
                    'type' => 'string',
                ],
            ],
            'required' => [
                'name',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function negatedInstanceofDoesNotNarrowAnObjectToNull(): void
    {
        $schema = $this->getClosureReturnSchema(function (object $value): mixed {
            if (! ($value instanceof SimpleClass)) {
                exit;

            } else {
                return $value;
            }
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'n' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
            ],
            'required' => [
                'n',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function orInsideIf(): void
    {
        $schema = $this->getClosureReturnSchema(function (object $value): int|string {
            if ($value instanceof StateEnum || $value instanceof PermissionEnum) {
                return $value->value;
            }

            return 0;
        });

        $this->assertSchemaArraysMatch([
            'type' => [
                'integer',
                'string',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function orInsideIfReturnsEnum(): void
    {
        $schema = $this->getClosureReturnSchema(function (object $value): mixed {
            if ($value instanceof StateEnum || $value instanceof PermissionEnum) {
                return $value;
            }

            return 0;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'description' => '[StateEnum](#/schemas/StateEnum)',
                    'enum' => [
                        1,
                        2,
                    ],
                    'type' => 'integer',
                ],
                [
                    'description' => '[PermissionEnum](#/schemas/PermissionEnum)',
                    'enum' => [
                        'read',
                        'write',
                    ],
                    'type' => 'string',
                ],
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function droppedUnknownTypeWidensSurvivingScalarValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $a, int $b): int|string {
            if (rand(0, 1)) {
                return max($a, $b);
            }

            if (rand(0, 1)) {
                return 5;
            }

            return 'fixed';
        });

        // `max(...)` is unknown to the analyzer. Because it merges with the other
        // return types, the surviving `5` and `'fixed'` must widen to plain
        // integer/string rather than claiming `const: 5` / `const: 'fixed'` — the
        // unknown stands for other possible return values.
        $this->assertSchemaArraysMatch([
            'type' => [
                'string',
                'integer',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nullCheckNarrowsVariableInTheTrueBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            // `$value !== null` removes null from the union in the true branch, so
            // `return $value` is the non-null SimpleClass — only the `else` branch
            // contributes the string.
            if ($value !== null) {
                return $value;

            } else {
                return 'missing';
            }
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function earlyReturnGuardNarrowsVariableAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if ($value === null) {
                return 'missing';
            }

            // The guard returns when $value is null, so after it $value must be
            // the non-null SimpleClass — `null` must not leak into the response.
            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nestedConditionalReturnDoesNotMakeGuardBranchBreakOut(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if ($value === null) {
                if (rand(0, 1)) {
                    return 'missing';
                }
            }

            // The outer guard branch can fall through, so the final return can
            // still be null.
            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
                [
                    'type' => 'null',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function issetNarrowsVariableInTheTrueBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if (isset($value)) {
                return $value;
            }

            return 'missing';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function throwGuardNarrowsVariableAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if ($value === null) {
                throw new \RuntimeException('missing');
            }

            // The guard throws when $value is null, so after it $value must be the
            // non-null SimpleClass — `null` must not leak into the response.
            return $value;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'n' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
            ],
            'required' => [
                'n',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function negatedInstanceofInElseRemovesTheClassFromTheUnion(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : new Rocket;

            if ($value instanceof Rocket) {
                return 'rocket';

            } else {
                // Not a Rocket, so the union must be narrowed to SimpleClass only.
                return $value;
            }
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'rocket',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function elseifBranchUsesNegatedPreviousConditions(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : new Rocket;

            if ($value instanceof Rocket) {
                return 'rocket';

            } elseif (rand(0, 1)) {
                // Reaching this branch means the first condition was false, so
                // $value must be SimpleClass, not Rocket.
                return $value;
            }

            return 'done';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'rocket',
                        'done',
                    ],
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function negatedIsStringInElseRemovesStringFromTheUnion(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? 5 : 'hello';

            if (is_string($value)) {
                return 'str';

            } else {
                // Not a string, so the union must be narrowed to the integer.
                return $value;
            }
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'str',
                    'type' => 'string',
                ],
                [
                    'const' => 5,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function elseifGuardChainNarrowsVariableAfterTheChain(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if (rand(0, 1)) {
                return 'a';

            } elseif ($value === null) {
                return 'b';

            }

            // Every branch returns, so reaching here means $value !== null.
            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'a',
                        'b',
                    ],
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function equalityToLiteralNarrowsVariableInTheTrueBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (string $type): mixed {
            if ($type === 'json') {
                // `$type` is narrowed to the literal 'json' here.
                return $type;
            }

            return 0;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'json',
                    'type' => 'string',
                ],
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function literalEqualityNarrowsFiniteUnionToLiteral(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $type = rand(0, 1) ? 'json' : 'xml';

            if ($type === 'json') {
                return $type;
            }

            return 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'json',
                'fallback',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function literalInequalityRemovesLiteralFromFiniteUnion(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $type = rand(0, 1) ? 'json' : 'xml';

            if ($type !== 'json') {
                return $type;
            }

            return 'matched';
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'xml',
                'matched',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function discriminatorLiteralCheckNarrowsArrayShapeUnion(): void
    {
        $closure =
            /**
             * @param array{type: 'user', userId: int, name?: string}|array{type: 'org', orgId: int, plan?: string} $payload
             */
            function (array $payload): mixed {
                if ($payload['type'] !== 'user') {
                    exit;
                }

                return $payload;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'type' => [
                    'const' => 'user',
                    'type' => 'string',
                ],
                'userId' => [
                    'type' => 'integer',
                ],
                'name' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'type',
                'userId',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function negatedDiscriminatorLiteralCheckNarrowsArrayShapeUnion(): void
    {
        $closure =
            /**
             * @param array{type: 'user', userId: int, name?: string}|array{type: 'org', orgId: int, plan?: string} $payload
             */
            function (array $payload): mixed {
                if ($payload['type'] === 'user') {
                    exit;
                }

                return $payload;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'type' => [
                    'const' => 'org',
                    'type' => 'string',
                ],
                'orgId' => [
                    'type' => 'integer',
                ],
                'plan' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'type',
                'orgId',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function inArrayDiscriminatorCheckNarrowsArrayShapeUnion(): void
    {
        $closure =
            /**
             * @param array{type: 'user', userId: int, name?: string}|array{type: 'org', orgId: int, plan?: string}|array{type: 'bot', token: string} $payload
             */
            function (array $payload): mixed {
                if (! in_array($payload['type'], ['user', 'org'], true)) {
                    exit;
                }

                return $payload;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'const' => 'user',
                            'type' => 'string',
                        ],
                        'userId' => [
                            'type' => 'integer',
                        ],
                        'name' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'type',
                        'userId',
                    ],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'const' => 'org',
                            'type' => 'string',
                        ],
                        'orgId' => [
                            'type' => 'integer',
                        ],
                        'plan' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'type',
                        'orgId',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function looseEqualityNarrowsFiniteUnionToLooselyMatchingLiteral(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => 1,
                1 => '1',
                default => 'two',
            };

            // Loose `==` narrows by PHP's loose comparison rules, so `1` and `'1'`
            // both match `'1'` while `'two'` does not.
            if ($value == '1') {
                return $value;
            }

            return 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 1,
                    'type' => 'integer',
                ],
                [
                    'enum' => [
                        '1',
                        'fallback',
                    ],
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function looseInequalityRemovesLooselyMatchingLiteralsFromFiniteUnion(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => 1,
                1 => '1',
                default => 'two',
            };

            // `!= '1'` keeps only values that do not loosely match `'1'`, i.e. `'two'`.
            if ($value != '1') {
                return $value;
            }

            return 'matched';
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'two',
                'matched',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function looseEqualityToNonNumericStringExcludesNumbersUnderPhp8(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => 0,
                1 => 'foo',
                default => 'bar',
            };

            if ($value == 'foo') {
                return $value;
            }

            return 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'foo',
                'fallback',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function looseNullEqualityGuardNarrowsVariableAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if ($value == null) {
                return 'missing';
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function looseNullEqualityGuardRemovesValuesThatLooselyEqualNull(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 5)) {
                0 => null,
                1 => 0,
                2 => '',
                3 => 1,
                4 => '0',
                default => 'ready',
            };

            if ($value == null) {
                return 'loosely-null';
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'loosely-null',
                        '0',
                        'ready',
                    ],
                    'type' => 'string',
                ],
                [
                    'const' => 1,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function looseNullEqualityBranchKeepsValuesThatLooselyEqualNull(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 5)) {
                0 => null,
                1 => 0,
                2 => '',
                3 => 1,
                4 => '0',
                default => 'ready',
            };

            if ($value == null) {
                return $value;
            }

            return 'not-loosely-null';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
                [
                    'enum' => [
                        '',
                        'not-loosely-null',
                    ],
                    'type' => 'string',
                ],
                [
                    'type' => 'null',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function falseyBranchKeepsOnlyFalseyLiteralValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 5)) {
                0 => null,
                1 => 0,
                2 => '',
                3 => 1,
                4 => '0',
                default => 'ready',
            };

            if (! $value) {
                return $value;
            }

            return 'truthy';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
                [
                    'enum' => [
                        '',
                        '0',
                        'truthy',
                    ],
                    'type' => 'string',
                ],
                [
                    'type' => 'null',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function truthyBranchKeepsOnlyTruthyLiteralValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 5)) {
                0 => null,
                1 => 0,
                2 => '',
                3 => 1,
                4 => '0',
                default => 'ready',
            };

            if ($value) {
                return $value;
            }

            return 'falsey';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 1,
                    'type' => 'integer',
                ],
                [
                    'enum' => [
                        'ready',
                        'falsey',
                    ],
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nestedTruthinessAndLooseComparisonsNarrowEachBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 5)) {
                0 => null,
                1 => 0,
                2 => '',
                3 => 1,
                4 => '0',
                default => 'ready',
            };

            if ($value) {
                if ($value == 'ready') {
                    return 'truthy-ready';

                } else {
                    return $value;
                }
            }

            if ($value == null) {
                if ($value === null) {
                    return 'null-value';

                } else if ($value === '') {
                    return 'empty-string';

                } else {
                    return $value;
                }

            } else {
                return $value;
            }
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'truthy-ready',
                        'null-value',
                        'empty-string',
                        '0',
                    ],
                    'type' => 'string',
                ],
                [
                    'enum' => [
                        1,
                        0,
                    ],
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nestedInstanceofTypeChecksAndThrowsNarrowEachBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 6)) {
                0 => new SimpleClass(rand(0, 1) ? 5 : null),
                1 => new Rocket,
                2 => 0,
                3 => 7,
                4 => '',
                5 => 'alpha',
                default => null,
            };

            if ($value instanceof SimpleClass) {
                if ($value->n === null) {
                    throw new \RuntimeException('missing number');
                }

                return $value->n;
            }

            if ($value instanceof Rocket) {
                throw new \RuntimeException('no rockets');
            }

            if (is_string($value)) {
                if (! $value) {
                    return 'empty-string';
                }

                return $value;
            }

            if (is_int($value)) {
                if (! $value) {
                    throw new \RuntimeException('zero');
                }

                return $value;
            }

            return 'null-value';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'empty-string',
                        'alpha',
                        'null-value',
                    ],
                    'type' => 'string',
                ],
                [
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function impossibleConditionNarrowsVariableToNever(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? 5 : null;

            // $value is 5|null, so the is_string branch is unreachable:
            // $value narrows to `never`, and `return $value` contributes nothing
            // to the result type.
            if (is_string($value)) { // @phpstan-ignore function.impossibleType
                return $value;
            }

            return 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'const' => 'fallback',
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nativeNeverReturnTypeResolvesToNever(): void
    {
        $schema = $this->getClosureReturnSchema(function (): never {
            throw new \RuntimeException('abort');
        });

        $this->assertSchemaArraysMatch([
            'enum' => [],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function phpDocNeverReturnTypeResolvesToNever(): void
    {
        $schema = $this->getClosureReturnSchema(
            /**
             * @return never
             */
            function (): mixed {
                throw new \RuntimeException('abort');
            },
            usePhpDocIfAvailable: true,
        );

        $this->assertSchemaArraysMatch([
            'enum' => [],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function analyzedNeverReturnTypeResolvesToNever(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            throw new \RuntimeException('abort');
        });

        $this->assertSchemaArraysMatch([
            'enum' => [],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function neverReturnTypeIsAbsorbedByOtherReturnTypes(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            if (rand(0, 1)) {
                return 'ok';
            }

            return \AutoDoc\Tests\Analyzer\controlFlowAnalysisAbort();
        });

        $this->assertSchemaArraysMatch([
            'const' => 'ok',
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function neverExpressionIsAbsorbedFromTernaryReturn(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            return rand(0, 1)
                ? 'ok'
                : \AutoDoc\Tests\Analyzer\controlFlowAnalysisAbort();
        });

        $this->assertSchemaArraysMatch([
            'const' => 'ok',
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function neverReturningCallNarrowsVariableAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            if ($value === null) {
                \AutoDoc\Tests\Analyzer\controlFlowAnalysisAbort();
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'n' => [
                    'type' => [
                        'integer',
                        'null',
                    ],
                ],
            ],
            'required' => [
                'n',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function strictInArrayNarrowsVariableToLiteralValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $type = match (rand(0, 2)) {
                0 => 'json',
                1 => 'xml',
                default => 'yaml',
            };

            if (in_array($type, ['json', 'xml'], true)) {
                return $type;
            }

            return 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'json',
                'xml',
                'fallback',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nonStrictInArrayNarrowsVariableToLooselyMatchingLiteralValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => 1,
                1 => '1',
                default => 'two',
            };

            if (in_array($value, ['1'])) {
                return $value;
            }

            return 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 1,
                    'type' => 'integer',
                ],
                [
                    'enum' => [
                        '1',
                        'fallback',
                    ],
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function strictInArrayNarrowsArrayElementPathToLiteralValues(): void
    {
        $closure =
            /**
             * @param array{id: int, status: 'draft'|'published'|'archived'} $data
             */
            function (array $data): mixed {
                if (! in_array($data['status'], ['draft', 'published'], true)) {
                    exit;
                }

                return $data;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                ],
                'status' => [
                    'enum' => [
                        'draft',
                        'published',
                    ],
                    'type' => 'string',
                ],
            ],
            'required' => [
                'id',
                'status',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterWithoutCallbackRemovesFalseyLiteralValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 6)) {
                0 => null,
                1 => false,
                2 => 0,
                3 => '',
                4 => '0',
                5 => 1,
                default => 'ready',
            };

            return array_filter(['value' => $value]);
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'value' => [
                    'anyOf' => [
                        [
                            'const' => 1,
                            'type' => 'integer',
                        ],
                        [
                            'const' => 'ready',
                            'type' => 'string',
                        ],
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterCallbackInstanceofNarrowsItemType(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => new SimpleClass,
                1 => new Rocket,
                default => null,
            };

            return array_filter(
                [$value],
                fn (mixed $item): bool => $item instanceof SimpleClass,
            );
        });

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'n' => [
                        'type' => [
                            'integer',
                            'null',
                        ],
                    ],
                ],
                'required' => [
                    'n',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterNamedIsStringCallbackNarrowsItemType(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => 'ready',
                1 => 5,
                default => null,
            };

            return array_filter([$value], 'is_string');
        });

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'items' => [
                'const' => 'ready',
                'type' => 'string',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterBackslashNamedIsBoolCallbackNarrowsItemType(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 2)) {
                0 => true,
                1 => 5,
                default => 'ready',
            };

            return array_filter([$value], '\\is_bool');
        });

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'items' => [
                'type' => 'boolean',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterUseKeyModeAppliesCallbackToKeysWithoutNarrowingValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            return array_filter(
                [
                    'stringKey' => 5,
                    10 => 'numeric-key',
                ],
                'is_string',
                ARRAY_FILTER_USE_KEY,
            );
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'stringKey' => [
                    'const' => 5,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterUseKeyModeWithClosureNarrowsKeysWithoutNarrowingValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            return array_filter(
                [
                    'name' => 5,
                    7 => 9,
                ],
                fn (mixed $key): bool => is_string($key),
                ARRAY_FILTER_USE_KEY,
            );
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'name' => [
                    'const' => 5,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterUseBothModeNarrowsKeysAndValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = match (rand(0, 1)) {
                0 => 5,
                default => 'five',
            };

            return array_filter(
                [
                    'count' => $value,
                    10 => $value,
                ],
                fn (mixed $v, mixed $k): bool => is_string($k) && is_int($v),
                ARRAY_FILTER_USE_BOTH,
            );
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'count' => [
                    'const' => 5,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayFilterCallbackDiscriminatorNarrowsItemShapeUnion(): void
    {
        $closure =
            /**
             * @param list<array{type: 'user', userId: int, name?: string}|array{type: 'org', orgId: int, plan?: string}> $items
             */
            function (array $items): mixed {
                return array_values(array_filter(
                    $items,
                    fn (mixed $item): bool => is_array($item) && $item['type'] === 'user',
                ));
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'const' => 'user',
                        'type' => 'string',
                    ],
                    'userId' => [
                        'type' => 'integer',
                    ],
                    'name' => [
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'type',
                    'userId',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function switchCaseNarrowsTheSubjectToTheCaseValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (string $type): mixed {
            switch ($type) {
                case 'json':
                    return $type;

                case 'xml':
                    return $type;

                default:
                    return 0;
            }
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'json',
                        'xml',
                    ],
                    'type' => 'string',
                ],
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function switchCaseNarrowsObjectPropertySubjectToTheCaseValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $box = new GenericClass(rand(0, 1) ? 'json' : 'xml');

            switch ($box->data) {
                case 'json':
                    return $box->data;

                default:
                    return 'fallback';
            }
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'json',
                'fallback',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function fallThroughSwitchCasesNarrowSubjectToTheUnionOfValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (string $type): mixed {
            switch ($type) {
                case 'a':
                case 'b':
                    // Reached for both 'a' and 'b', so $type is 'a'|'b' here.
                    return $type;

                default:
                    return 0;
            }
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'a',
                        'b',
                    ],
                    'type' => 'string',
                ],
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function switchDefaultCaseRemovesPreviousLiteralCaseValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $type = rand(0, 1) ? 'json' : 'xml';

            switch ($type) {
                case 'json':
                    return 'matched';

                default:
                    return $type;
            }
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'matched',
                'xml',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function neverCallPreventsSwitchCaseFallthroughNarrowing(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $type = rand(0, 1) ? 'json' : 'xml';

            switch ($type) {
                case 'json':
                    \AutoDoc\Tests\Analyzer\controlFlowAnalysisAbort();

                default:
                    // The `json` case cannot fall through because the call never
                    // returns, so the default branch can only see `xml`.
                    return $type;
            }
        });

        $this->assertSchemaArraysMatch([
            'const' => 'xml',
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function matchArmNarrowsTheSubjectToTheArmValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (string $type): mixed {
            return match ($type) {
                'json' => $type,
                'xml' => $type,
                default => 0,
            };
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'enum' => [
                        'json',
                        'xml',
                    ],
                    'type' => 'string',
                ],
                [
                    'const' => 0,
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function matchArmNarrowsObjectPropertySubjectToTheArmValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $box = new GenericClass(rand(0, 1) ? 'json' : 'xml');

            return match ($box->data) {
                'json' => $box->data,
                default => 'fallback',
            };
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'json',
                'fallback',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function matchDefaultArmRemovesPreviousLiteralArmValues(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $type = rand(0, 1) ? 'json' : 'xml';

            // The default arm can only run when `$type` did not match 'json', so
            // it should keep the surviving finite value, not the whole union.
            return match ($type) {
                'json' => 'matched',
                default => $type,
            };
        });

        $this->assertSchemaArraysMatch([
            'enum' => [
                'matched',
                'xml',
            ],
            'type' => 'string',
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function switchAssignmentsAreResolvedAfterTheSwitch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            switch (rand(0, 1)) {
                case 0:
                    $state = 'zero';
                    break;

                default:
                    $state = 'other';
                    break;
            }

            return [
                'state' => $state,
                'after' => 'done',
            ];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'state' => [
                    'type' => 'string',
                    'enum' => [
                        'zero',
                        'other',
                    ],
                ],
                'after' => [
                    'const' => 'done',
                    'type' => 'string',
                ],
            ],
            'required' => [
                'state',
                'after',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nullCheckInTernaryConditionNarrowsTheTrueBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            // The ternary condition `$value !== null` removes null from the union
            // in the true expression, so the `SimpleClass` branch must not carry
            // null — only the `else` expression contributes the string.
            return $value !== null ? $value : 'missing';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function instanceofInTernaryConditionNarrowsTheFalseBranch(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : new Rocket;

            // The ternary's `else` expression is reached only when the condition is
            // false, so `$value` there must be narrowed to SimpleClass — Rocket
            // must not leak into it.
            return $value instanceof Rocket ? 'rocket' : $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'rocket',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function shortTernaryRemovesNullFromTheLeftOperand(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            // `$value ?: 'fallback'` evaluates to `$value` only when it is truthy,
            // so the result is the non-null SimpleClass or the fallback string —
            // null must not leak through the left operand.
            return $value ?: 'fallback';
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
                [
                    'const' => 'fallback',
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function matchTrueArmNarrowsVariableByInstanceof(): void
    {
        $schema = $this->getClosureReturnSchema(function (object $value): mixed {
            // `match (true)` arms are boolean conditions, so `$value` must be
            // narrowed to StateEnum inside the arm whose condition holds.
            return match (true) {
                $value instanceof StateEnum => $value,
                default => 'other',
            };
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'description' => '[StateEnum](#/schemas/StateEnum)',
                    'enum' => [
                        1,
                        2,
                    ],
                    'type' => 'integer',
                ],
                [
                    'const' => 'other',
                    'type' => 'string',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function matchTrueDefaultArmUsesNegatedPreviousConditions(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : new Rocket;

            // The default arm runs only when the Rocket arm did not match, so
            // `$value` there must be narrowed to SimpleClass.
            return match (true) {
                $value instanceof Rocket => 'rocket',
                default => $value,
            };
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'rocket',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function notEmptyGuardNarrowsVariableAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            // `empty($value)` is true when $value is null, so the guard returns for
            // that case and after it $value must be the non-null SimpleClass —
            // `null` must not leak into the response.
            if (empty($value)) {
                return 'missing';
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function notEmptyGuardNarrowsOptionalArrayElementToPresentTruthyValues(): void
    {
        $closure =
            /**
             * @param array{code?: null|0|''|'0'|1|'ready', tag: 'payload'} $data
             */
            function (array $data): mixed {
                if (empty($data['code'])) {
                    exit;
                }

                return $data;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'code' => [
                    'anyOf' => [
                        [
                            'const' => 1,
                            'type' => 'integer',
                        ],
                        [
                            'const' => 'ready',
                            'type' => 'string',
                        ],
                    ],
                ],
                'tag' => [
                    'const' => 'payload',
                    'type' => 'string',
                ],
            ],
            'required' => [
                'code',
                'tag',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function truthyGuardNarrowsVariableAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = rand(0, 1) ? new SimpleClass : null;

            // A falsey guard returns before the final read, so after it the value
            // is known to be non-null for the same reason as !empty($value).
            if (! $value) {
                return 'missing';
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nullGuardNarrowsObjectProperty(): void
    {
        $schema = $this->getClosureReturnSchema(function (SimpleClass $obj): mixed {
            // The guard returns when `$obj->n` is null, so after it the property
            // must be the non-null int — `null` must not leak into the response.
            if ($obj->n === null) {
                return 'missing';
            }

            return $obj->n;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function instanceofGuardNarrowsGenericObjectPropertyAfterTheGuard(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $box = new GenericClass(rand(0, 1) ? new SimpleClass : null);

            if (! ($box->data instanceof SimpleClass)) {
                return 'missing';
            }

            return $box->data;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nullGuardNarrowsArrayElementWithLiteralKey(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = ['item' => rand(0, 1) ? new SimpleClass : null];

            // The guard returns when `$data['item']` is null, so after it the
            // element must be the non-null SimpleClass.
            if ($data['item'] === null) {
                return 'missing';
            }

            return $data['item'];
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function narrowingArrayElementKeepsTheKeyRequired(): void
    {
        $schema = $this->getClosureReturnSchema(function (SimpleClass $obj): mixed {
            $data = ['value' => $obj->n]; // value: int|null, present and required

            // The guard breaks out when `$data['value']` is null, narrowing the
            // element to the non-null int. The key was present in the original
            // shape, so returning the whole array must keep `value` required.
            if ($data['value'] === null) {
                exit;
            }

            return $data;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'integer',
                ],
            ],
            'required' => [
                'value',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function narrowingNestedPropertyKeepsTheKeysRequiredWhenReturningTheRoot(): void
    {
        $schema = $this->getClosureReturnSchema(function (NestedPropertyRoot $a): mixed {
            // The guard breaks out when `$a->b->c` is falsey, narrowing the nested
            // property to a non-null int. Returning the whole root must keep both
            // the intermediate `b` and the leaf `c` required.
            if (! $a->b->c) {
                exit;
            }

            return $a;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'b' => [
                    'type' => 'object',
                    'properties' => [
                        'c' => [
                            'type' => 'integer',
                        ],
                    ],
                    'required' => [
                        'c',
                    ],
                ],
            ],
            'required' => [
                'b',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function presenceCheckNarrowsOptionalKeyToRequired(): void
    {
        $closure =
            /**
             * @param array{id: int, email?: string} $data
             */
            function (array $data): mixed {
                // `email` is optional, but the guard guarantees it is present after
                // it, so returning the array must mark `email` required.
                if (! isset($data['email'])) {
                    exit;
                }

                return $data;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                ],
                'email' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'id',
                'email',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function arrayKeyExistsNarrowsOptionalKeyToRequiredWithoutRemovingNull(): void
    {
        $closure =
            /**
             * @param array{id: int, email?: string|null} $data
             */
            function (array $data): mixed {
                // `array_key_exists` proves the key is present, but unlike
                // `isset`, it does not prove the value is non-null.
                if (! array_key_exists('email', $data)) {
                    exit;
                }

                return $data;
            };

        $schema = $this->getClosureReturnSchema($closure);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                ],
                'email' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                ],
            ],
            'required' => [
                'id',
                'email',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function falseyGuardNarrowsArrayElementWhenReturningTheArray(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = [
                'code' => match (rand(0, 5)) {
                    0 => null,
                    1 => 0,
                    2 => '',
                    3 => 1,
                    4 => '0',
                    default => 'ready',
                },
                'tag' => 'payload',
            ];

            if (! $data['code']) {
                return $data;
            }

            return [
                'code' => 'truthy',
                'tag' => 'payload',
            ];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'code' => [
                    'anyOf' => [
                        [
                            'const' => 0,
                            'type' => 'integer',
                        ],
                        [
                            'enum' => [
                                '',
                                '0',
                                'truthy',
                            ],
                            'type' => 'string',
                        ],
                        [
                            'type' => 'null',
                        ],
                    ],
                ],
                'tag' => [
                    'const' => 'payload',
                    'type' => 'string',
                ],
            ],
            'required' => [
                'code',
                'tag',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function mutatingSiblingArrayElementPreservesElementNarrowing(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = [
                'item' => rand(0, 1) ? new SimpleClass : null,
                'meta' => 'a',
            ];

            if ($data['item'] === null) {
                return 'missing';
            }

            $data['meta'] = 'b';

            return $data['item'];
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function mutatingNarrowedArrayElementDiscardsElementNarrowing(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = [
                'item' => rand(0, 1) ? new SimpleClass : null,
            ];

            if ($data['item'] === null) {
                return 'missing';
            }

            $data['item'] = rand(0, 1) ? new SimpleClass : null;

            return $data['item'];
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'n' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                    'required' => [
                        'n',
                    ],
                ],
                [
                    'type' => 'null',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function nullGuardNarrowsNestedObjectProperty(): void
    {
        $schema = $this->getClosureReturnSchema(function (NestedPropertyRoot $a): mixed {
            if ($a->b->c === null) {
                return 'missing';
            }

            return $a->b->c;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'integer',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function mutatingNarrowedNestedObjectPropertyDiscardsPropertyNarrowing(): void
    {
        $schema = $this->getClosureReturnSchema(function (NestedPropertyRoot $a): mixed {
            if ($a->b->c === null) {
                return 'missing';
            }

            $a->b->c = rand(0, 1) ? 5 : null;

            return $a->b->c;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'const' => 5,
                    'type' => 'integer',
                ],
                [
                    'type' => 'null',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function reassigningTheObjectDiscardsPropertyNarrowing(): void
    {
        $schema = $this->getClosureReturnSchema(function (SimpleClass $obj): mixed {
            if ($obj->n === null) {
                return 'missing';
            }

            // Reassigning $obj after the guard discards the property narrowing —
            // the fresh instance's `n` can be null again, so it must reappear.
            $obj = new SimpleClass;

            return $obj->n;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'const' => 'missing',
                    'type' => 'string',
                ],
                [
                    'type' => 'integer',
                ],
                [
                    'type' => 'null',
                ],
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function mergingTwoIdenticalShapesKeepsRequiredKeys(): void
    {
        $schema = $this->getClosureReturnSchema(function (bool $flag): mixed {
            if ($flag) {
                return ['id' => 1, 'name' => 'first'];
            }

            return ['id' => 2, 'name' => 'second'];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'id' => [
                    'enum' => [1, 2],
                    'type' => 'integer',
                ],
                'name' => [
                    'enum' => ['first', 'second'],
                    'type' => 'string',
                ],
            ],
            'required' => [
                'id',
                'name',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function namedArgumentDoesNotMisbindToASkippedTemplateParameter(): void
    {
        // `columns` is skipped, so it resolves to its default (`0`) rather than
        // binding `TColumns` to the named `pageName` argument.
        $schema = $this->getClosureReturnSchema(
            fn (): mixed => ControlFlowAnalysisTest::paginateColumns(50, pageName: 'page_number'),
        );

        $this->assertSchemaArraysMatch([
            'type' => 'integer',
            'const' => 0,
        ], $schema, 'closure', 'return');
    }

    /**
     * Mirrors the shape of Laravel's `paginate()`: a template-typed `$columns`
     * sitting between a positional and a named-only argument.
     *
     * @template TColumns
     *
     * @param TColumns $columns
     *
     * @return TColumns
     */
    private static function paginateColumns(int $perPage, mixed $columns = 0, string $pageName = 'page'): mixed
    {
        return $columns;
    }

    #[Test]
    public function bodyAnalysisDoesNotMisbindNamedArgumentToASkippedParameter(): void
    {
        // `columns` is skipped; body analysis must bind it to its default (`0`),
        // not the named `pageName` argument occupying the same positional slot.
        $schema = $this->getClosureReturnSchema(
            fn (): mixed => ControlFlowAnalysisTest::paginateColumnsViaBody(50, pageName: 'page_number'),
        );

        $this->assertSchemaArraysMatch([
            'type' => 'integer',
            'const' => 0,
        ], $schema, 'closure', 'return');
    }

    /**
     * Like {@see paginateColumns()} but with no `@return`, so the return type
     * comes from analyzing the body (exercising `FunctionNodeVisitor` parameter
     * binding rather than `PhpCallable::getArgumentType()`).
     */
    private static function paginateColumnsViaBody(int $perPage, mixed $columns = 0, string $pageName = 'page'): mixed
    {
        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(\Closure $closure, bool $usePhpDocIfAvailable = false): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;
        $config->data['intersections']['render_empty_as_unknown'] = false;

        $scope = new Scope($config);
        $type = (new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        ))->getReturnType(usePhpDocIfAvailable: $usePhpDocIfAvailable);

        return $type->toSchema($config);
    }
}

function controlFlowAnalysisAbort(): never
{
    throw new \RuntimeException('abort');
}
