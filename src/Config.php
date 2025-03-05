<?php declare(strict_types=1);

namespace AutoDoc;

use Exception;

/**
 * @phpstan-type EnumConfig array{
 *     autodetect_components?: bool,
 *     remove_namespace?: bool,
 *     remove_description?: bool,
 *     create_links?: bool,
 *     show_allowed_values?: bool,
 *     generate_description_from_cases?: bool,
 * }
 *
 * @phpstan-type WorkspaceConfig array{
 *     routes?: string[],
 *     schemas?: array<int|class-string, class-string|EnumConfig>,
 *     enum?: EnumConfig,
 *     export_filename?: string,
 *     access_token?: string,
 *     request_methods?: string[],
 * }
 *
 * @phpstan-type ConfigArray array{
 *     api: array{
 *         title?: string,
 *         version?: string,
 *         description?: string,
 *         domain?: string,
 *     },
 *     workspaces: WorkspaceConfig[],
 *     ui: array{
 *         theme?: 'light'|'dark',
 *         logo?: string,
 *         hide_try_it?: bool,
 *     },
 *     openapi: array{
 *         show_routes_as_titles?: bool,
 *         show_values_for_scalar_types?: bool,
 *     },
 *     schemas?: array<int|class-string, class-string|EnumConfig>,
 *     enum?: EnumConfig,
 *     openapi_export_dir: string,
 *     route_loader: class-string<AbstractRouteLoader>,
 *     extensions: array<class-string>,
 *     use_cache: bool,
 *     memory_limit: ?string,
 *     max_depth: int,
 *     debug: array{
 *         enabled: bool,
 *         ignore_dynamic_method_errors: bool,
 *     },
 *     ignore_dynamic_method_errors: bool,
 * }
 */
class Config
{
    public function __construct(
        /**
         * @var ConfigArray
         */
        public array $data,

        /**
         * @var ?WorkspaceConfig
         */
        public ?array $selectedWorkspace = null,
    ) {}


    /**
     * @return EnumConfig
     */
    public function getEnumConfig(string $className): array
    {
        $workspaceSchemaSpecificConfig = $this->selectedWorkspace['schemas'][$className] ?? [];
        $globalSchemaSpecificConfig = $this->data['schemas'][$className] ?? [];

        $workspaceSchemaSpecificConfig = is_array($workspaceSchemaSpecificConfig) ? $workspaceSchemaSpecificConfig : [];
        $globalSchemaSpecificConfig = is_array($globalSchemaSpecificConfig) ? $globalSchemaSpecificConfig : [];

        return array_merge(
            $this->data['enum'] ?? [],
            $this->selectedWorkspace['enum'] ?? [],
            $globalSchemaSpecificConfig,
            $workspaceSchemaSpecificConfig,
        );
    }


    /**
     * @return array<class-string, array<string, mixed>>
     */
    public function getSchemasConfig(): array
    {
        $schemaConfigs = array_merge(
            $this->selectedWorkspace['schemas'] ?? [],
            $this->data['schemas'] ?? [],
        );

        $schemas = [];

        foreach ($schemaConfigs as $key => $enum) {
            if (is_string($key) && is_array($enum)) {
                $schemas[$key] = $enum;

            } else if (is_string($enum)) {
                $schemas[$enum] = [];
            }
        }

        return $schemas;
    }


    public function getRouteLoader(): AbstractRouteLoader
    {
        if (empty($this->data['route_loader'])) {
            throw new Exception('Autodoc route loader is not configured');
        }

        return new $this->data['route_loader']($this);
    }


    public static function getDefaultConfigFilePath(): string
    {
        return __DIR__ . '/../config/autodoc.php';
    }
}
