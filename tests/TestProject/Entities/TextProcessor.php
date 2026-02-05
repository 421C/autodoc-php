<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class TextProcessor
{
    /**
     * @phpstan-ignore missingType.iterableValue
     */
    public function process(string $text): array
    {
        return [
            'text' => $text,
        ];
    }
}
