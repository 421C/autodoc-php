<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\Type;

class PhpVariable
{
    public function __construct(
        public readonly string $name,

        /**
         * @var array<int, array<int, PhpVariableMutation>>
         */
        public array $mutations = [],
    ) {}

    /**
     * @return Type[]
     */
    public function getDirectAssignmentTypes(): array
    {
        $assignments = [];

        foreach ($this->mutations as $mutationsOnLine) {
            foreach ($mutationsOnLine as $mutation) {
                if (isset($mutation->changes['type'])) {
                    $assignments[] = $mutation->changes['type'];
                }
            }
        }

        return $assignments;
    }
}
