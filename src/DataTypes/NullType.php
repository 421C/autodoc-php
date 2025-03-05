<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

class NullType extends Type
{
    public function toSchema(): array
    {
        return array_filter([
            'type' => 'null',
            'description' => $this->description,
        ]);
    }
}
