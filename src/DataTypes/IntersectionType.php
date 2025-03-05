<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\DataTypes\Traits\WithMergeableTypes;


class IntersectionType extends Type
{
    use WithMergeableTypes;

    public function __construct(
        /**
         * @var Type[]
         */
        public array $types = [],
    ) {}


    public function toSchema(): array
    {
        $this->mergeDuplicateTypes(mergeAsIntersection: true);

        $type = $this->unwrapType();

        if (! ($type instanceof IntersectionType)) {
            return $type->toSchema();
        }

        return [
            'allOf' => array_map(fn ($type) => $type->toSchema(), $this->types),
        ];
    }
}
