<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Exceptions\AutoDocException;
use AutoDoc\OpenApi\InfoObject;
use AutoDoc\OpenApi\RootSchema;
use AutoDoc\OpenApi\Server;
use Throwable;

class DocGenerator
{
    /**
     * @param array{start?: (callable(Route): void), end?: (callable(Route): void)}|null $reportProgress
     */
    public function makeOpenApi(Config $config, ?array $reportProgress = null): RootSchema
    {
        if (isset($config->data['memory_limit'])) {
            ini_set('memory_limit', $config->data['memory_limit']);
        }

        $openApi = new RootSchema;

        $openApi->info = new InfoObject(
            title: $config->data['api']['title'] ?? '',
            version: $config->data['api']['version'] ?? '',
            description: $config->data['api']['description'] ?? '',
        );

        $openApi->paths = $config->getRouteLoader()->getOpenApiPaths($reportProgress);

        $openApi->servers[] = new Server(
            $config->data['api']['domain'] ?? '',
            '',
        );

        $openApi->components['schemas'] = $this->getSchemaComponents($config);

        return $openApi;
    }


    /**
     * @return array<string, array<string, mixed>>
     */
    private function getSchemaComponents(Config $config): array
    {
        $scope = new Scope($config);

        foreach ($config->getSchemasConfig() as $schemaClass => $schemaConfig) {
            try {
                /**
                 * When an enum is resolved, it is automatically added to `Workspace::$schemaComponents`.
                 */
                $scope->getPhpClass($schemaClass)->resolveType();

            } catch (Throwable $exception) {
                if ($scope->isDebugModeEnabled()) {
                    throw new AutoDocException('Error adding schema component "' . $schemaClass . '": ', $exception);
                }
            }
        }

        return array_map(fn ($type) => $type->toSchema($config), Workspace::$schemaComponents);
    }
}
