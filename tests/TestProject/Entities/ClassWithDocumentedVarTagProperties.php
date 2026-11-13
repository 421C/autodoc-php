<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class ClassWithDocumentedVarTagProperties
{
    /** @var string Plain var tag. */
    public string $plain;

    /** @var ?string Nullable var tag. */
    public ?string $nullable;
}
