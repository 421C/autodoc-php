<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

class BoolType extends Type
{
    public function toSchema(): array
    {
        return array_filter([
            'type' => 'boolean',
            'description' => $this->description,
        ]);
    }
}
