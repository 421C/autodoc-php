<?php declare(strict_types=1);

namespace AutoDoc\Tests\TestProject\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Extensions\OperationExtension;
use AutoDoc\OpenApi\Operation;
use AutoDoc\Route;

class AppendApiKeySecurityExtension extends OperationExtension
{
    public function handle(Operation $operation, Route $route, Scope $scope): ?Operation
    {
        $operation->addSecurityRequirement(['apiKeyAuth' => []]);

        return $operation;
    }
}
