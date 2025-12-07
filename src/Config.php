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
 *     exact_routes?: string[],
 *     schemas?: array<int|class-string, class-string|EnumConfig>,
 *     enum?: EnumConfig,
 *     export_filename?: string,
 *     access_token?: string,
 *     request_methods?: string[],
 * }
 *
 * @phpstan-type TypeScriptConfigRaw array{
 *     working_directory?: string,
 *     file_extensions?: string[],
 *     indent?: string,
 *     string_quote?: string,
 *     add_semicolons?: bool,
 *     show_values_for_scalar_types?: bool,
 *     save_types_in_single_file?: string|null,
 *     modes?: array<string, array<string, mixed>>,
 *     path_prefixes?: class-string<object&callable(Config $config): iterable<string, string>>|callable(Config $config): iterable<string, string>,
 *     tsconfig_path?: string,
 *     export_http_requests_and_responses?: array<string, array{
 *         routes?: string[],
 *         exact_routes?: string[],
 *         request_methods?: string[],
 *     }>,
 * }
 *
 * @phpstan-type TypeScriptConfig array{
 *     working_directory?: string,
 *     file_extensions: string[],
 *     indent: string,
 *     string_quote: string,
 *     add_semicolons: bool,
 *     show_values_for_scalar_types: bool,
 *     save_types_in_single_file?: string|null,
 *     modes: array<string, array<string, mixed>>,
 *     path_prefixes: iterable<string, string>,
 *     tsconfig_path?: string,
 *     export_http_requests_and_responses?: array<string, array{
 *         routes?: string[],
 *         exact_routes?: string[],
 *         request_methods?: string[],
 *     }>,
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
 *         use_pattern_for_numeric_strings?: bool,
 *     },
 *     schemas?: array<int|class-string, class-string|EnumConfig>,
 *     enum?: EnumConfig,
 *     classes?: array{
 *         remove_description?: bool,
 *     },
 *     arrays?: array{
 *         remove_scalar_type_values_when_merging_with_unknown_types?: bool,
 *         resolve_partial_shapes?: bool,
 *     },
 *     openapi_export_dir: string,
 *     route_loader: class-string<AbstractRouteLoader>,
 *     extensions: array<class-string>,
 *     use_cache: bool,
 *     memory_limit: ?string,
 *     max_depth: int,
 *     debug?: array{
 *         enabled: bool,
 *         ignore_dynamic_method_errors: bool,
 *     },
 *     typescript?: TypeScriptConfigRaw,
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
        public int|string|null $selectedWorkspaceKey = null,
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


    /**
     * @return TypeScriptConfig
     */
    public function getTypeScriptConfig(?string $mode = null): array
    {
        $defaults = [
            'file_extensions' => ['ts', 'tsx', 'vue'],
            'indent' => '    ',
            'string_quote' => "'",
            'add_semicolons' => false,
            'show_values_for_scalar_types' => true,
            'modes' => [],
            'path_prefixes' => fn () => [],
        ];

        /** @var TypeScriptConfigRaw */
        $modeConfig = $mode ? ($this->data['typescript']['modes'][$mode] ?? []) : [];

        $tsConfig = array_merge($defaults, $this->data['typescript'] ?? [], $modeConfig);

        $pathPrefixesLoader = $tsConfig['path_prefixes'];

        if (is_string($pathPrefixesLoader)) {
            if (class_exists($pathPrefixesLoader) && method_exists($pathPrefixesLoader, '__invoke')) {
                $pathPrefixesLoader = (new $pathPrefixesLoader)(...);

            } else {
                throw new Exception("Error: path_prefixes in autodoc config is not an invokable class name or callable. '$pathPrefixesLoader' given.");
            }
        }

        /** @phpstan-ignore function.alreadyNarrowedType */
        if (is_callable($pathPrefixesLoader)) {
            $tsConfig['path_prefixes'] = $pathPrefixesLoader($this);

            if (! is_iterable($tsConfig['path_prefixes'])) {
                throw new Exception('Error: path_prefixes in autodoc config must return an iterable.');
            }

        } else {
            $type = gettype($pathPrefixesLoader);

            throw new Exception("Error: path_prefixes in autodoc config is of type $type. It must be an invokable class name or a function of type `callable(Config \$config): iterable<string, string>`.");
        }

        return $tsConfig;
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
