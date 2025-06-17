<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;
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


    public function toSchema(?Config $config = null): array
    {
        $this->mergeDuplicateTypes(mergeAsIntersection: true, config: $config);

        $type = $this->unwrapType($config);

        if (! ($type instanceof IntersectionType)) {
            return $type->toSchema($config);
        }

        return [
            'allOf' => array_map(fn ($type) => $type->toSchema($config), $this->types),
        ];
    }
}
