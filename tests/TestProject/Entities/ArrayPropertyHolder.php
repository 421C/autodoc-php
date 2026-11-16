<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class ArrayPropertyHolder
{
    /**
     * @var array{name: string, secret?: string}
     */
    public array $data = ['name' => ''];
}
