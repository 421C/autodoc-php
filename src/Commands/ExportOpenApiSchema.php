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
    /**
     * @var array<string, string>
     */
    private array $console = [
        'reset' => "\033[0m",
        'muted' => "\033[90m",
        'white' => "\033[37m",
    ];


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
                    'start' => function (Route $route) {
                        echo 'Read ' . $this->console['white'] . $route->uri . $this->console['reset'];
                    },
                    'end' => function (Route $route, float $seconds) {
                        if ($seconds < 1) {
                            $elapsed = number_format($seconds * 1000, 2) . ' ms';

                        } else {
                            $elapsed = number_format($seconds, 2) . ' s';
                        }

                        echo $this->console['muted'] . ' (' . $elapsed . ')' . $this->console['reset'] . PHP_EOL;
                    },
                ],
            );

            if (! $exportedFileName) {
                throw new Exception('Failed to export OpenApi schema for workspace `' . $workspace->key . '`');
            }

            $this->log('');
            $this->log('Workspace `' . $workspace->key . '` OpenApi schema saved to ' . $this->formatFilePath($exportedFileName));
            $this->log('');
        }
    }

    protected function log(string $message): void
    {
        echo $message . PHP_EOL;
    }

    protected function formatFilePath(string $fullPath): string
    {
        $cwd = getcwd();

        if ($cwd && str_starts_with($fullPath, $cwd)) {
            return ltrim(substr($fullPath, strlen($cwd)), '\\/');
        }

        return $fullPath;
    }
}
