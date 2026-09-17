<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class FluentBuilderChild extends FluentBuilder
{
    public string $childValue = 'x';

    public function withNativeParent(): parent
    {
        return $this;
    }
}
