<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class VoidType extends Type
{
    public function toSchema(?Config $config = null): array
    {
        return [
            'type' => 'null',
        ];
    }
}
