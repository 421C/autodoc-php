<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

/**
 * @method $this withMagic()
 */
class FluentBuilder
{
    public int $baseValue = 1;

    /**
     * @return $this
     */
    public function withThis(): static
    {
        return $this;
    }

    /**
     * @return $this
     */
    public function withThisAndNoNativeType()
    {
        return $this;
    }

    /**
     * @return static
     */
    public function withDocStatic(): static
    {
        return $this;
    }

    /**
     * @return self
     */
    public function withDocSelf(): self
    {
        return $this;
    }

    public function withNativeStatic(): static
    {
        return $this;
    }

    public function withNativeSelf(): self
    {
        return $this;
    }

    public function withExplicitClass(): FluentBuilder
    {
        return $this;
    }
}
