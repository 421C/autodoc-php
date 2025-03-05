<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\DataTypes\Type;
use Exception;


class Workspace
{
    public function __construct(
        private Config $config,
    ) {}


    public static function getDefault(Config $config): ?Workspace
    {
        foreach ($config->data['workspaces'] as $workspaceConfig) {
            if (empty($workspaceConfig['access_token'])) {

                $config->selectedWorkspace = $workspaceConfig;

                return new Workspace($config);
            }
        }

        return null;
    }

    public static function findUsingToken(string $accessToken, Config $config): ?Workspace
    {
        foreach ($config->data['workspaces'] as $workspaceConfig) {
            if (isset($workspaceConfig['access_token']) && $workspaceConfig['access_token'] === $accessToken) {

                $config->selectedWorkspace = $workspaceConfig;

                return new Workspace($config);
            }
        }

        return null;
    }


    public function getJson(): ?string
    {
        $useCache = $this->config->data['use_cache'] ?? true;

        $exportDir = rtrim($this->config->data['openapi_export_dir'] ?? '', '/');
        $exportFileName = ltrim($this->config->selectedWorkspace['export_filename'] ?? '', '/');

        if (! $exportDir) {
            throw new Exception('Autodoc `openapi_export_dir` is empty.');
        }

        if (! $exportFileName) {
            throw new Exception('Autodoc workspace `export_filename` is empty.');
        }

        $openApiFilePath = $exportDir . '/' . $exportFileName;

        if (! $useCache || ! file_exists($openApiFilePath)) {
            $docGenerator = new DocGenerator;

            $openApiSchema = $docGenerator->makeOpenApi($this->config);

            $openApiSchemaJson = json_encode($openApiSchema);

            if (! $openApiSchemaJson) {
                return null;
            }

            file_put_contents($openApiFilePath, $openApiSchemaJson);

            return $openApiSchemaJson;
        }

        return file_get_contents($openApiFilePath) ?: null;
    }

    /**
     * @var array<string, Type>
     */
    public static array $schemaComponents = [];
}
