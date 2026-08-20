<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

class DocumentedResultServiceConsumer
{
    public function __construct(
        private DocumentedResultService $someService,
    ) {}

    public function result(): mixed
    {
        return $this->someService->method();
    }
}
