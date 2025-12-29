<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\DataTypes\Type;
use Exception;


class Workspace
{
    public function __construct(
        private Config $config,
        public int|string|null $key = null,
    ) {}


    public static function getDefault(Config $config): ?Workspace
    {
        foreach ($config->data['workspaces'] as $key => $workspaceConfig) {
            if (empty($workspaceConfig['access_token'])) {
                return new Workspace($config, $key);
            }
        }

        return null;
    }

    public static function findUsingToken(string $accessToken, Config $config): ?Workspace
    {
        foreach ($config->data['workspaces'] as $key => $workspaceConfig) {
            if (isset($workspaceConfig['access_token']) && $workspaceConfig['access_token'] === $accessToken) {
                return new Workspace($config, $key);
            }
        }

        return null;
    }

    public static function findUsingKey(string $key, Config $config): ?Workspace
    {
        $workspaceConfig = $config->data['workspaces'][$key] ?? null;

        if (! $workspaceConfig) {
            throw new Exception('Workspace `' . $key . '` not found.');
        }

        return new Workspace($config, $key);
    }

    /**
     * Generate and save an OpenApi 3.1 schema JSON file.
     *
     * @param array{
     *     start?: (callable(Route): void),
     *     end?: (callable(Route, float): void),
     * }|null $reportProgress
     */
    public function getJson(bool $returnFileName = false, ?array $reportProgress = null): ?string
    {
        $this->config->selectedWorkspaceKey = $this->key;
        $this->config->selectedWorkspace = $this->config->data['workspaces'][$this->config->selectedWorkspaceKey] ?? null;

        if (! $this->config->selectedWorkspace) {
            throw new Exception('Workspace not found (using key "' . $this->config->selectedWorkspaceKey . '").');
        }

        $useCache = $this->config->data['use_cache'] ?? true;
        $exportDir = rtrim($this->config->data['openapi_export_dir'] ?? '', '/\\');

        if (! $exportDir) {
            throw new Exception('Autodoc `openapi_export_dir` is empty.');
        }

        $exportFileName = ltrim($this->config->selectedWorkspace['export_filename'] ?? '', '/\\');

        if (! $exportFileName) {
            if (is_string($this->config->selectedWorkspaceKey)) {
                $exportFileName = $this->config->selectedWorkspaceKey . '.json';

            } else {
                throw new Exception('Autodoc workspace `export_filename` is empty.');
            }
        }

        $openApiFilePath = $exportDir . DIRECTORY_SEPARATOR . $exportFileName;

        if (! $useCache || ! file_exists($openApiFilePath)) {
            $docGenerator = new DocGenerator;

            $openApiSchema = $docGenerator->makeOpenApi($this->config, $reportProgress);

            $openApiSchemaJson = json_encode($openApiSchema, JSON_PRETTY_PRINT);

            if (! $openApiSchemaJson) {
                return null;
            }

            file_put_contents($openApiFilePath, $openApiSchemaJson);

            if ($returnFileName) {
                return $openApiFilePath;
            }

            return $openApiSchemaJson;
        }

        if ($returnFileName) {
            return $openApiFilePath;
        }

        return file_get_contents($openApiFilePath) ?: null;
    }

    /**
     * @var array<string, Type>
     */
    public static array $schemaComponents = [];
}
