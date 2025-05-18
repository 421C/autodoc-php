<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Config;

class UnknownType extends Type
{
    public function __construct(
        public ?string $description = null,
    ) {}

    public function toSchema(?Config $config = null): array
    {
        return array_filter([
            'type' => 'string',
            'description' => $this->description,
            'examples' => $this->examples,
        ]);
    }
}
