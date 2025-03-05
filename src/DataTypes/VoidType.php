<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

class VoidType extends Type
{
    public function toSchema(): array
    {
        return [
            'type' => 'null',
        ];
    }
}
