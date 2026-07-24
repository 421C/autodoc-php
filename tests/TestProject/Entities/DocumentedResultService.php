<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use RuntimeException;

class DocumentedResultService
{
    /**
     * @return array{message: string}
     */
    public function method(): array
    {
        throw new RuntimeException;
    }
}
