<?php declare(strict_types=1);

namespace AutoDoc\Extensions;

use AutoDoc\Analyzer\Scope;
use AutoDoc\OpenApi\Operation;
use AutoDoc\Route;

abstract class OperationExtension
{
    abstract public function handle(Operation $operation, Route $route, Scope $scope): ?Operation;
}
