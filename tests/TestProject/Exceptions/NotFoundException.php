<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Exceptions;

use Exception;

class NotFoundException extends Exception
{
    /**
     * @phpstan-ignore missingType.iterableValue
     */
    public function render(): array
    {
        return [
            'name' => 'not_found',
            'message' => $this->getMessage(),
        ];
    }
}
