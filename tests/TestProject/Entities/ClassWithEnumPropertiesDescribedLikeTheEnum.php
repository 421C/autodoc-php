<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class ClassWithEnumPropertiesDescribedLikeTheEnum
{
    /** View type (HTML or PDF) */
    public DocumentViewType $viewType;

    /** View type (HTML or PDF) */
    public ?DocumentViewType $nullableViewType;

    /** Requested view type */
    public DocumentViewType $requestedViewType;
}
