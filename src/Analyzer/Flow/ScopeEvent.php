<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Flow;

use AutoDoc\Analyzer\Narrowing\Narrowing;
use AutoDoc\DataTypes\Type;

/**
 * @phpstan-type ScopeEventChanges array{
 *     type?: Type,
 *     attributes?: array<int|string, Type>,
 *     narrowing?: Narrowing,
 *     narrowingPath?: list<int|string>,
 *     mutationPath?: list<int|string|null>,
 *     dynamicAttribute?: Type,
 * }
 */
class ScopeEvent
{
    public function __construct(
        public readonly ScopeEventType $type,
        public readonly string $varName,
        public readonly BranchPath $branchPath,

        /**
         * @var ScopeEventChanges
         */
        public readonly array $changes = [],

        /** Start position of the event in source code. */
        public readonly int $startFilePos = 0,

        /** End position of the event in source code (end of the value expression). */
        public readonly int $endFilePos = 0,

        /**
         * For Narrow events: the condition that produced this narrowing.
         * Null for Assign/Mutate events.
         */
        public readonly ?PhpCondition $condition = null,

        public readonly bool $isTypeAnnotation = false,
    ) {}
}
