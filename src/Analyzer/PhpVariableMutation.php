<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;

/**
 * @phpstan-type VariableMutationChanges array{
 *     type?: Type,
 *     attributes?: array<int|string, Type>,
 *     removedAttributes?: array<int|string>,
 * }
 */
class PhpVariableMutation
{
    public function __construct(
        /**
         * @var VariableMutationChanges
         */
        public array $changes,
        public readonly int $startFilePos,
        public readonly int $endFilePos,
        public readonly int $depth,

        /**
         * @var PhpCondition[]
         */
        public readonly array $conditions = [],
    ) {}
}
