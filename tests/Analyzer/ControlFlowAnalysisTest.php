<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
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

    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(\Closure $closure): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $scope = new Scope($config);
        $type = (new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        ))->getReturnType(usePhpDocIfAvailable: false);

        return $type->toSchema($config);
    }
}
