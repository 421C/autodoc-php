<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use RuntimeException;

final class BranchJoinTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    private const array INTEGER_LIST = [
        'type' => 'array',
        'items' => [
            'type' => 'integer',
        ],
    ];

    private const array INTEGER_LIST_OR_MAP = [
        'anyOf' => [
            [
                'type' => 'array',
                'items' => [
                    'type' => 'integer',
                ],
            ],
            [
                'type' => 'object',
                'additionalProperties' => [
                    'type' => 'integer',
                ],
            ],
        ],
    ];


    #[Test]
    public function reassigningAListAfterAnUnsetInABranchKeepsItAList(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $key): mixed {
            $rows = BranchJoinTest::ints();

            if (rand(0, 1)) {
                unset($rows[$key]);
                $rows = array_values($rows);
            }

            return $rows;
        });

        $this->assertSchemaArraysMatch(self::INTEGER_LIST, $schema, 'closure', 'return');
    }


    #[Test]
    public function reassigningANestedListAfterAnUnsetInABranchKeepsItAList(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $key): mixed {
            $data = ['rows' => BranchJoinTest::ints()];

            if (rand(0, 1)) {
                unset($data['rows'][$key]);
                $data['rows'] = array_values($data['rows']);
            }

            return $data;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'rows' => self::INTEGER_LIST,
            ],
            'required' => [
                'rows',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function reassigningAListAfterUnsetsInALoopInABranchKeepsItAList(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $rows = BranchJoinTest::ints();

            if (rand(0, 1)) {
                foreach (BranchJoinTest::ints() as $key) {
                    unset($rows[$key]);
                }

                $rows = array_values($rows);
            }

            return $rows;
        });

        $this->assertSchemaArraysMatch(self::INTEGER_LIST, $schema, 'closure', 'return');
    }


    #[Test]
    public function reassigningAListAfterAnUnsetInANestedBranchKeepsItAList(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $key): mixed {
            $rows = BranchJoinTest::ints();

            if (rand(0, 1)) {
                if (rand(0, 1)) {
                    unset($rows[$key]);
                }

                $rows = array_values($rows);
            }

            return $rows;
        });

        $this->assertSchemaArraysMatch(self::INTEGER_LIST, $schema, 'closure', 'return');
    }


    #[Test]
    public function anUnsetInABranchStillMayLeaveAGap(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $key): mixed {
            $rows = BranchJoinTest::ints();

            if (rand(0, 1)) {
                unset($rows[$key]);
            }

            return $rows;
        });

        $this->assertSchemaArraysMatch(self::INTEGER_LIST_OR_MAP, $schema, 'closure', 'return');
    }


    #[Test]
    public function aReassignmentInANestedBranchKeepsTheUnsetBeforeIt(): void
    {
        $schema = $this->getClosureReturnSchema(function (int $key): mixed {
            $rows = BranchJoinTest::ints();

            if (rand(0, 1)) {
                unset($rows[$key]);

                if (rand(0, 1)) {
                    $rows = array_values($rows);
                }
            }

            return $rows;
        });

        $this->assertSchemaArraysMatch(self::INTEGER_LIST_OR_MAP, $schema, 'closure', 'return');
    }


    #[Test]
    public function aLaterAssignmentInABranchReplacesAnEarlierOne(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = 1.5;

            if (rand(0, 1)) {
                $value = 'temporary';
                $value = true;
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'number',
                    'format' => 'float',
                    'const' => 1.5,
                ],
                [
                    'type' => 'boolean',
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aLaterAssignmentInALoopReplacesAnEarlierOne(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = 1.5;

            foreach (BranchJoinTest::ints() as $item) {
                $value = 'temporary';
                $value = true;
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'number',
                    'format' => 'float',
                    'const' => 1.5,
                ],
                [
                    'type' => 'boolean',
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function assignmentsInEveryBranchOfAnIfElseReplaceTheValueBeforeIt(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = null;

            if (rand(0, 1)) {
                $value = 1;

            } else {
                $value = 'a';
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'integer',
                    'const' => 1,
                ],
                [
                    'type' => 'string',
                    'const' => 'a',
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function assignmentsInEveryBranchThatFallsThroughReplaceTheValueBeforeIt(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = null;

            if (rand(0, 1)) {
                $value = 1;

            } else if (rand(0, 1)) {
                $value = 'a';

            } else {
                throw new RuntimeException;
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'anyOf' => [
                [
                    'type' => 'integer',
                    'const' => 1,
                ],
                [
                    'type' => 'string',
                    'const' => 'a',
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anAssignmentInAnIfWithoutElseKeepsTheValueBeforeIt(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $value = null;

            if (rand(0, 1)) {
                $value = 1;
            }

            return $value;
        });

        $this->assertSchemaArraysMatch([
            'type' => [
                'integer',
                'null',
            ],
            'enum' => [
                1,
                null,
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aMutationAfterAReassignmentInABranchStaysOnTheReassignedValue(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = ['a' => 1];

            if (rand(0, 1)) {
                $data = ['b' => 2];
                $data['c'] = 3;
            }

            return $data;
        });

        $this->assertSchemaArraysMatch([
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
                        'c' => [
                            'type' => 'integer',
                            'const' => 3,
                        ],
                    ],
                    'required' => [
                        'b',
                        'c',
                    ],
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aMutationInABranchAddsAnOptionalKey(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = ['a' => 1];

            if (rand(0, 1)) {
                $data['b'] = 2;
            }

            return $data;
        });

        $this->assertSchemaArraysMatch([
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
            'required' => [
                'a',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function mutationsInEveryBranchOfAnIfElseAddOptionalKeys(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = ['a' => 1];

            if (rand(0, 1)) {
                $data['b'] = 2;

            } else {
                $data['c'] = 3;
            }

            return $data;
        });

        $this->assertSchemaArraysMatch([
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
                'c' => [
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
    public function aWriteInABranchToAnUndefinedVariableAddsAnOptionalKey(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            if (rand(0, 1)) {
                $data['a'] = 1;
            }

            // @phpstan-ignore variable.undefined
            return $data;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'a' => [
                    'type' => 'integer',
                    'const' => 1,
                ],
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aNestedWriteInABranchToAnUndefinedVariableAddsAnOptionalKey(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            if (rand(0, 1)) {
                $data['group']['a'] = 1;
            }

            // @phpstan-ignore variable.undefined
            return $data;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'group' => [
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
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anAppendInABranchToAnUndefinedVariableMayLeaveItEmpty(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            if (rand(0, 1)) {
                $rows[] = 1;
            }

            // @phpstan-ignore variable.undefined
            return $rows;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'array',
            'items' => [
                'type' => 'integer',
                'const' => 1,
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function writesInEveryBranchOfAnIfElseToAnUndefinedVariableAddOptionalKeys(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            if (rand(0, 1)) {
                $data['a'] = 1;

            } else {
                $data['b'] = 2;
            }

            return $data;
        });

        $this->assertSchemaArraysMatch([
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
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function anAssignmentInABranchToAnUndefinedVariableAddsNoArray(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            if (rand(0, 1)) {
                $value = 1;
            }

            // @phpstan-ignore variable.undefined
            return $value;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'integer',
            'const' => 1,
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aNestedWriteReadsTheEntryBeforeTheWrite(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = ['rows' => BranchJoinTest::ints()];
            $data['rows'] = array_reverse($data['rows']);

            return $data;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'rows' => self::INTEGER_LIST,
            ],
            'required' => [
                'rows',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aDeeplyNestedWriteReadsTheEntryBeforeTheWrite(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $data = ['group' => ['rows' => BranchJoinTest::ints()]];
            $data['group']['rows'] = array_reverse($data['group']['rows']);

            return $data['group'];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'rows' => self::INTEGER_LIST,
            ],
            'required' => [
                'rows',
            ],
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aWriteUnderADynamicKeyReadsTheEntryBeforeTheWrite(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $rows = BranchJoinTest::ints();

            foreach (BranchJoinTest::ints() as $key) {
                $rows[$key] = $rows[$key] ?? 0;
            }

            return $rows;
        });

        $this->assertSchemaArraysMatch(self::INTEGER_LIST, $schema, 'closure', 'return');
    }


    #[Test]
    public function aCompoundWriteReadsTheEntryBeforeTheWrite(): void
    {
        $schema = $this->getClosureReturnSchema(function (?int $count): mixed {
            $data = ['count' => $count];
            $data['count'] ??= 0;

            return $data['count'];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'integer',
        ], $schema, 'closure', 'return');
    }


    #[Test]
    public function aPropertyWriteReadsThePropertyBeforeTheWrite(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            $item = new SimpleClass;
            $item->n = $item->n ?? 0;

            return $item->n;
        });

        $this->assertSchemaArraysMatch([
            'type' => 'integer',
        ], $schema, 'closure', 'return');
    }


    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $type = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType();

        return $type->toSchema($config);
    }


    /**
     * @return list<int>
     */
    private static function ints(): array
    {
        return json_decode('[]', true);
    }
}
