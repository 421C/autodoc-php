<?php declare(strict_types=1);

namespace AutoDoc\Tests\Traits;

use AutoDoc\Config;
use AutoDoc\Tests\TestProject\RouteLoader;

trait LoadsConfig
{
    protected static function loadConfig(): Config
    {
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

        return $config;
    }
}
