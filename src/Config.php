<?php declare(strict_types=1);

namespace AutoDoc;

use AutoDoc\Extensions\BuiltIn\ArrayFuncCall;
use AutoDoc\Extensions\BuiltIn\EnumStaticCall;
use AutoDoc\Extensions\BuiltIn\TypeCheckFuncCall;
use AutoDoc\Extensions\ClassExtension;
use AutoDoc\Extensions\FuncCallExtension;
use AutoDoc\Extensions\MethodCallExtension;
use AutoDoc\Extensions\OperationExtension;
use AutoDoc\Extensions\StaticCallExtension;
use AutoDoc\Extensions\ThrowExtension;
use AutoDoc\Extensions\TypeScriptExportExtension;
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
 * @phpstan-type WikiPageConfig array{id: string, title: string, url?: string, path?: string}
 *
 * @phpstan-type UiConfig array{
 *     theme?: 'system'|'light'|'dark',
 *     logo?: string,
 *     wiki_pages?: list<WikiPageConfig>,
 *     route_groups?: list<array{title: string, routes?: list<string>, exact_routes?: list<string>, collapsed?: bool}>,
 *     sidebar?: array{
 *         routes?: array{
 *             show_path?: bool,
 *             show_title?: bool,
 *             show_method?: bool,
 *             show_path_above_title?: bool,
 *         },
 *     },
 *     try_it?: array{
 *         enabled?: bool,
 *         proxy_url?: string,
 *     },
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
 *     ui?: UiConfig,
 * }
 *
 * @phpstan-type TypeScriptRouteExportConfig array{
 *     routes?: string[],
 *     exact_routes?: string[],
 *     request_methods?: string[],
 *     include_requests_without_body?: bool,
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
 *     export_http_requests_and_responses?: array<string, TypeScriptRouteExportConfig>,
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
 *     export_http_requests_and_responses?: array<string, TypeScriptRouteExportConfig>,
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
 *     workspaces_json_dir?: string,
 *     ui: UiConfig,
 *     openapi: array{
 *         show_values_for_scalar_types?: bool,
 *         use_pattern_for_numeric_strings?: bool,
 *         json_pretty_print?: bool,
 *     },
 *     schemas?: array<int|class-string, class-string|EnumConfig>,
 *     enum?: EnumConfig,
 *     classes?: array{
 *         remove_description?: bool,
 *     },
 *     arrays?: array{
 *         remove_scalar_type_values_when_merging_with_unknown_types?: bool,
 *         resolve_partial_shapes?: bool,
 *         deep_shape_inference?: bool,
 *         merge_shapes_in_type_unions?: bool,
 *     },
 *     objects?: array{
 *         merge_shapes_in_type_unions?: bool,
 *     },
 *     intersections?: array{
 *         coercive_scalar_overlap?: bool,
 *         render_empty_as_unknown?: bool,
 *     },
 *     openapi_export_dir: string,
 *     route_loader: class-string<AbstractRouteLoader>,
 *     extensions?: array<class-string>,
 *     use_cache: bool,
 *     memory_limit: ?string,
 *     max_depth: int,
 *     debug?: array{
 *         enabled?: bool,
 *         ignore_dynamic_method_errors?: bool,
 *         ignore_unknown_method_errors_in_traits?: bool,
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
     * @var ?array<class-string, list<class-string>>
     */
    private ?array $extensionsByType = null;

    /**
     * Merged workspaces (JSON dir + inline), cached after first resolution.
     *
     * @var ?array<int|string, WorkspaceConfig>
     */
    private ?array $workspaces = null;

    /**
     * Resolved TypeScript config per mode ('' = no mode).
     *
     * @var array<string, TypeScriptConfig>
     */
    private array $typeScriptConfigByMode = [];


    /**
     * @return array<int|string, WorkspaceConfig>
     */
    public function getWorkspaces(): array
    {
        if ($this->workspaces !== null) {
            return $this->workspaces;
        }

        $this->workspaces = array_merge($this->loadJsonWorkspaces(), $this->data['workspaces']);

        return $this->workspaces;
    }


    /**
     * @return ?WorkspaceConfig
     */
    public function getWorkspace(int|string $key): ?array
    {
        return $this->getWorkspaces()[$key] ?? null;
    }


    /**
     * @return array<int|string, WorkspaceConfig>
     */
    private function loadJsonWorkspaces(): array
    {
        $dir = $this->data['workspaces_json_dir'] ?? null;

        if (! $dir) {
            return [];
        }

        if (! is_dir($dir)) {
            throw new Exception("Autodoc `workspaces_json_dir` is not a directory: '$dir'.");
        }

        $workspaces = [];

        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $contents = file_get_contents($file);
            $decoded = $contents === false ? null : json_decode($contents, true);

            if (! is_array($decoded)) {
                throw new Exception("Autodoc workspace file is not valid JSON: '$file'.");
            }

            /** @var WorkspaceConfig $decoded */
            $workspaces[basename($file, '.json')] = $decoded;
        }

        return $workspaces;
    }


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
        return $this->typeScriptConfigByMode[$mode ?? ''] ??= $this->resolveTypeScriptConfig($mode);
    }

    /**
     * @return TypeScriptConfig
     */
    private function resolveTypeScriptConfig(?string $mode): array
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
            /** @var ?iterable<string, string> */
            $prefixes = $pathPrefixesLoader($this);

            if (! is_iterable($prefixes)) {
                throw new Exception('Error: path_prefixes in autodoc config must return an iterable.');
            }

            $tsConfig['path_prefixes'] = is_array($prefixes) ? $prefixes : iterator_to_array($prefixes);

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


    /**
     * @return array<class-string, list<class-string>>
     */
    public function getExtensions(): array
    {
        if ($this->extensionsByType !== null) {
            return $this->extensionsByType;
        }

        $this->extensionsByType = [];

        foreach ($this->data['extensions'] ?? [] as $extensionClass) {
            if (is_subclass_of($extensionClass, MethodCallExtension::class)) {
                $this->extensionsByType[MethodCallExtension::class][] = $extensionClass;

            } else if (is_subclass_of($extensionClass, FuncCallExtension::class)) {
                $this->extensionsByType[FuncCallExtension::class][] = $extensionClass;

            } else if (is_subclass_of($extensionClass, StaticCallExtension::class)) {
                $this->extensionsByType[StaticCallExtension::class][] = $extensionClass;

            } else if (is_subclass_of($extensionClass, ClassExtension::class)) {
                $this->extensionsByType[ClassExtension::class][] = $extensionClass;

            } else if (is_subclass_of($extensionClass, OperationExtension::class)) {
                $this->extensionsByType[OperationExtension::class][] = $extensionClass;

            } else if (is_subclass_of($extensionClass, ThrowExtension::class)) {
                $this->extensionsByType[ThrowExtension::class][] = $extensionClass;

            } else if (is_subclass_of($extensionClass, TypeScriptExportExtension::class)) {
                $this->extensionsByType[TypeScriptExportExtension::class][] = $extensionClass;
            }
        }

        // Built-ins run after configured extensions so consumers can override them.
        $this->extensionsByType[FuncCallExtension::class][] = ArrayFuncCall::class;
        $this->extensionsByType[FuncCallExtension::class][] = TypeCheckFuncCall::class;
        $this->extensionsByType[StaticCallExtension::class][] = EnumStaticCall::class;

        return $this->extensionsByType;
    }


    public static function getDefaultConfigFilePath(): string
    {
        return __DIR__ . '/../config/autodoc.php';
    }
}
