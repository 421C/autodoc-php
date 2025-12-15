<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;
use PhpParser\Node;

class PhpVariableAssignment
{
    public function __construct(
        public readonly Type $type,
        public readonly int $startFilePos,
        public readonly int $endFilePos,
        public readonly int $depth,

        /**
         * @var array<array{
         *     kind: 'if'|'elseif'|'else',
         *     condition: Node|null
         * }>
         */
        public readonly array $conditions = [],

        /**
         * @var array<int, PhpVariableMutation>
         */
        public readonly array $mutations = [],
    ) {}
}
