<?php declare(strict_types=1);

namespace AutoDoc\Tests\Traits;

use AutoDoc\Config;
use AutoDoc\Tests\TestProject\RouteLoader;

trait LoadsConfig
{
    protected static function loadConfig(): Config
    {
        /** @phpstan-ignore argument.type */
        $config = new Config(require __DIR__ . '/../../config/autodoc.php');

        $config->data['debug']['enabled'] = true;
        $config->data['debug']['ignore_dynamic_method_errors'] = false;
        $config->data['route_loader'] = RouteLoader::class;

        $config->data['typescript']['path_prefixes'] = fn () => ['@' => dirname(__DIR__) . '/typescript'];

        $config->data['typescript']['modes'] = [
            'double_quotes' => [
                'string_quote' => '"',
            ],
            'separate_file' => [
                'save_types_in_single_file' => '@/types.ts',
            ],
        ];

        $config->data['typescript']['export_http_requests_and_responses'] = [
            '@/requests-and-responses.ts' => [
                'routes' => ['/api/test/requestparams/query-param-array-of-strings', 'api/test/requestparams/headers-and-request-body', 'api/test/arrayoperations/array-values-on-assoc-array'],
                'exact_routes' => ['/api/test/basicresponses/array-shape-from-return-tag'],
                'request_methods' => ['get', 'post'],
            ],
        ];

        return $config;
    }
}
