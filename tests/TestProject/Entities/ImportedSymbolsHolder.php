<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Entities;

use AutoDoc\Tests\TestProject\Support\{function makeCount as groupedCount, const MAX_ATTEMPTS as GROUPED_MAX_ATTEMPTS};
use function AutoDoc\Tests\TestProject\Support\makeCount as countAlias;
use function AutoDoc\Tests\TestProject\Support\makeStatus;
use const AutoDoc\Tests\TestProject\Support\DEFAULT_STATUS;
use const AutoDoc\Tests\TestProject\Support\MAX_ATTEMPTS as MAX_ATTEMPTS_ALIAS;

class ImportedSymbolsHolder
{
    public function usesDirectImports(): mixed
    {
        return [
            'status' => makeStatus(),
            'count' => countAlias(),
            'defaultStatus' => DEFAULT_STATUS,
            'maxAttempts' => MAX_ATTEMPTS_ALIAS,
        ];
    }

    public function usesGroupImports(): mixed
    {
        return [
            'count' => groupedCount(),
            'maxAttempts' => GROUPED_MAX_ATTEMPTS,
        ];
    }
}
