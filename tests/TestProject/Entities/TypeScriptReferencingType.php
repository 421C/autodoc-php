<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

final class TypeScriptReferencingType
{
    public function __construct(
        public TypeScriptReferencedType $referenced,
    ) {}
}
