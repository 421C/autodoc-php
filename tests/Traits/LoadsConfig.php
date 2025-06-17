<?php declare(strict_types=1);

namespace AutoDoc\Tests\Traits;

use AutoDoc\Config;
use AutoDoc\Tests\TestProject\RouteLoader;

trait LoadsConfig
{
    protected function loadConfig(): Config
    {
        $config = new Config(require __DIR__ . '/../../config/autodoc.php');

        $config->data['debug']['enabled'] = true;
        $config->data['debug']['ignore_dynamic_method_errors'] = false;
        $config->data['route_loader'] = RouteLoader::class;

        return $config;
    }
}
