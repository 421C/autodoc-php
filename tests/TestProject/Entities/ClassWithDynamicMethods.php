<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @method ?int getStringOrInt()
 * @method Rocket[] getRockets(int $count = 1)
 * @method static StateEnum getState()
 */
class ClassWithDynamicMethods
{
    /**
     * @phpstan-ignore-next-line
     */
    public function __call(string $name, array $arguments)
    {
        return $name;
    }
}
