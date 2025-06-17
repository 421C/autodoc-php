<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class BoolType extends Type
{
    public function __construct(
        public ?bool $value = null,
    ) {}

    public function toSchema(?Config $config = null): array
    {
        return array_filter([
            'type' => 'boolean',
            'description' => $this->description,
        ]);
    }
}
