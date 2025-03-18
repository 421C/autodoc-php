<?php declare(strict_types=1);

namespace AutoDoc\Commands;

use AutoDoc\Config;
use AutoDoc\Route;
use AutoDoc\Workspace;
use Exception;

/**
 * Export OpenApi 3.1 schema JSON file(s)
 */
class ExportOpenApiSchema
{
    public function __invoke(
        Config $config,
        ?string $workspaceKey = null,
    ): void {
        if ($workspaceKey) {
            $workspace = Workspace::findUsingKey($workspaceKey, $config);

            if (! $workspace) {
                throw new Exception("Workspace with key '$workspaceKey' not found.");
            }

            $workspaces = [$workspace];

        } else {
            $workspaces = [];

            foreach ($config->data['workspaces'] as $key => $workspaceConfig) {
                $workspaces[] = new Workspace($config, $key);
            }
        }

        foreach ($workspaces as $workspace) {
            $this->log('Starting export for workspace `' . $workspace->key . '`');

            $exportedFileName = $workspace->getJson(
                returnFileName: true,
                reportProgress: [
                    'start' => fn (Route $route) => $this->log('Reading route ' . $route->uri . ' ... '),
                    'end' => fn (Route $route) => $this->log('DONE'),
                ],
            );

            $this->log('');
            $this->log('Workspace `' . $workspace->key . '` OpenApi schema saved to ' . $exportedFileName);
            $this->log('');
            $this->log('');
        }
    }

    protected function log(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
