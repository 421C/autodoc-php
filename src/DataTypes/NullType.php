<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class NullType extends Type
{
    public function toSchema(?Config $config = null): array
    {
        return array_filter([
            'type' => 'null',
            'description' => $this->description,
        ]);
    }
}
