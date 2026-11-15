<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class PropertyTypeSource
{
    /**
     * @var array{id: int, name: string}
     */
    public array $shape = ['id' => 0, 'name' => ''];

    /**
     * @var array{label: 'x', extra: mixed}
     */
    public array $literalShape = ['label' => 'x', 'extra' => null];

    /**
     * @var array<string, int>
     */
    public array $map = [];

    /**
     * @var list<int>
     */
    public array $list = [];

    /**
     * @var 'on'|'off'
     */
    public string $mode = 'on';
}
