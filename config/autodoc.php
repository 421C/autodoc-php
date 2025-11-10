<?php declare(strict_types=1);

return [
    /**
     * Information about your API that will be included in the exported OpenApi schema.
     */
    'api' => [
        'title' => $_ENV['APP_NAME'] ?? '',
        'version' => $_ENV['APP_VERSION'] ?? '',
        'domain' => $_ENV['APP_URL'] ?? '',
        'description' => '',
    ],

    /**
     * List of workspaces and their configuration.
     * Each workspace can contain multiple routes and will be exported as a separate OpenApi schema.
     */
    'workspaces' => [
        'your-api-name' => [
            'routes' => [
                '/',
            ],
        ],
    ],

    /**
     * Documentation page UI settings.
     */
    'ui' => [
        /**
         * Documentation page theme - light / dark.
         */
        'theme' => 'light',

        /**
         * Logo URL to show in documentation page.
         */
        'logo' => '',

        /**
         * Hide "Try it" panel in the documentation UI.
         */
        'hide_try_it' => false,
    ],

    'openapi' => [
        /**
         * When enabled, will show routes instead of operation names in sidebar and title.
         */
        'show_routes_as_titles' => true,

        /**
         * When enabled, will attempt to read possible values for returned scalar types.
         * Disabled by default because it is not guaranteed that all possible values will be detected.
         */
        'show_values_for_scalar_types' => false,

        /**
         * When enabled, will use pattern instead of format for numeric string types.
         */
        'use_pattern_for_numeric_strings' => false,
    ],

    /**
     * Directory where OpenApi schema files will be exported.
     */
    'openapi_export_dir' => '/path/to/openapi',

    /**
     * Enum handling settings.
     */
    'enum' => [
        /**
         * When enabled, all referenced enums will be exported as OpenApi schema components.
         * Otherwise only enums specified in `schemas` parameter will be exported.
         */
        'autodetect_components' => true,

        /**
         * Remove namespace from enum name.
         */
        'remove_namespace' => true,

        /**
         * Ignore description from enum PHPDoc comment.
         */
        'remove_description' => false,

        /**
         * Create links to enum schemas in the description of enum types.
         */
        'create_links' => true,

        /**
         * Show allowed values for enum types.
         */
        'show_allowed_values' => true,

        /**
         * OpenApi 3.1 does not support descriptions for enum cases but we can generate a HTML
         * description for the whole enum containing all its cases and their descriptions.
         */
        'generate_description_from_cases' => true,
    ],

    'classes' => [
        /**
         * Ignore description from class PHPDoc comment.
         */
        'remove_description' => false,
    ],

    'arrays' => [
        'remove_scalar_type_values_when_merging_with_unknown_types' => true,
    ],

    /**
     * Class that will be used to load and analyze routes.
     * This class must extend `AutoDoc\AbstractRouteLoader`.
     */
    'route_loader' => null,

    /**
     * List of extensions that will be loaded.
     */
    'extensions' => [],

    /**
     * Enable or disable OpenApi schema caching.
     * If this is disabled, schema will be generated on each request.
     */
    'use_cache' => false,

    /**
     * Memory limit for OpenApi schema generation.
     */
    'memory_limit' => null,

    /**
     * Maximum depth of nested types.
     */
    'max_depth' => 7,

    'debug' => [
        /**
         * Enable or disable error reporting.
         */
        'enabled' => false,

        /**
         * Ignore errors about non-existant methods on classes that have a
         * __call or __callStatic magic method.
         */
        'ignore_dynamic_method_errors' => true,
    ],

    /**
     * Read `@autodoc` tags in typescript code and export typescript schemas.
     */
    'typescript' => [
        'working_directory' => null, // '/path/to/your/typescript/code',
        'file_extensions' => ['ts', 'tsx', 'vue'],
        'indent' => '    ',
        'string_quote' => "'",
        'add_semicolons' => false,
        'show_values_for_scalar_types' => true,
    ],
];
