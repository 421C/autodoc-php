<?php declare(strict_types=1);

namespace AutoDoc\Commands;

use AutoDoc\Config;
use InvalidArgumentException;
use Throwable;

/**
 * @phpstan-import-type ConfigArray from Config
 */
final class CommandLine
{
    /**
     * @param string[] $arguments
     */
    public function run(array $arguments): int
    {
        if ($arguments === [] || $this->hasHelpOption($arguments)) {
            $this->printHelp();

            return 0;
        }

        $command = array_shift($arguments);

        try {
            return match ($command) {
                'openapi' => $this->exportOpenApi($arguments),
                'ts' => $this->updateTypeScript($arguments),
                'debug' => $this->debug($arguments),
                default => $this->unknownCommand($command),
            };
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * @param string[] $arguments
     */
    private function exportOpenApi(array $arguments): int
    {
        $config = $this->loadConfig($this->extractOption($arguments, 'config'));
        $workspaceKey = $this->extractPositionalArgument($arguments);

        (new ExportOpenApiSchema)($config, $workspaceKey);

        return 0;
    }

    /**
     * @param string[] $arguments
     */
    private function updateTypeScript(array $arguments): int
    {
        $config = $this->loadConfig($this->extractOption($arguments, 'config'));
        $workingDirectory = $this->extractPositionalArgument($arguments);
        $hasErrors = false;

        foreach ((new UpdateTypeScriptStructures($config))->run($workingDirectory) as $message) {
            if (isset($message['processedTags'])) {
                $tags = $message['processedTags'] . ' tag' . ($message['processedTags'] === 1 ? '' : 's');

                echo 'Updated ' . $message['filePath'] . ' (' . $tags . ')' . PHP_EOL;

            } else if (isset($message['exportedRequests'])) {
                $requestsAndResponses = $message['exportedRequests'] . ' request' . ($message['exportedRequests'] === 1 ? '' : 's') . ', '
                    . $message['exportedResponses'] . ' response' . ($message['exportedResponses'] === 1 ? '' : 's');

                echo 'Updated ' . $message['filePath'] . ' (' . $requestsAndResponses . ')' . PHP_EOL;

            } else {
                $hasErrors = true;
                $error = $message['error'];

                if ($error instanceof Throwable) {
                    $this->error($error->getMessage() . ' [' . $error->getFile() . ':' . $error->getLine() . ']');

                } else {
                    $this->error($error);
                }
            }
        }

        return $hasErrors ? 1 : 0;
    }

    /**
     * @param string[] $arguments
     */
    private function debug(array $arguments): int
    {
        $config = $this->loadConfig($this->extractOption($arguments, 'config'));
        $depth = $this->extractOption($arguments, 'depth');
        $workingDirectory = $this->extractPositionalArgument($arguments) ?? getcwd();

        if ($workingDirectory === false) {
            throw new InvalidArgumentException('Working directory not specified.');
        }

        if ($depth !== null && filter_var($depth, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Depth must be an integer.');
        }

        (new ProcessAutoDocDebugTags($config, $depth === null ? null : (int) $depth))($workingDirectory);

        return 0;
    }

    private function loadConfig(?string $configPath): Config
    {
        if ($configPath === null) {
            throw new InvalidArgumentException('Config file not specified.');
        }

        if (! is_file($configPath)) {
            throw new InvalidArgumentException('Config file not found: ' . $configPath);
        }

        $data = require $configPath;

        if (! is_array($data)) {
            throw new InvalidArgumentException('Config file must return an array: ' . $configPath);
        }

        /** @var ConfigArray $data */
        return new Config($data);
    }

    /**
     * @param string[] $arguments
     */
    private function extractOption(array &$arguments, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        $value = null;

        foreach ($arguments as $index => $argument) {
            if (! str_starts_with($argument, $prefix)) {
                continue;
            }

            if ($value !== null) {
                throw new InvalidArgumentException('Option --' . $name . ' may only be specified once.');
            }

            $value = substr($argument, strlen($prefix));
            unset($arguments[$index]);
        }

        if ($value === '') {
            throw new InvalidArgumentException('Option --' . $name . ' requires a value.');
        }

        return $value;
    }

    /**
     * @param string[] $arguments
     */
    private function extractPositionalArgument(array $arguments): ?string
    {
        $arguments = array_values($arguments);

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Unknown option: ' . $argument);
            }
        }

        if (count($arguments) > 1) {
            throw new InvalidArgumentException('Too many arguments.');
        }

        return $arguments[0] ?? null;
    }

    /**
     * @param string[] $arguments
     */
    private function hasHelpOption(array $arguments): bool
    {
        return in_array('--help', $arguments, true) || in_array('-h', $arguments, true);
    }

    private function unknownCommand(string $command): int
    {
        $this->error('Unknown command: ' . $command);
        $this->printHelp();

        return 1;
    }

    private function printHelp(): void
    {
        echo 'Usage:' . PHP_EOL;
        echo '  autodoc openapi [workspaceKey] --config=<path>' . PHP_EOL;
        echo '  autodoc ts [workingDirectory] --config=<path>' . PHP_EOL;
        echo '  autodoc debug [workingDirectory] --config=<path> [--depth=<number>]' . PHP_EOL;
    }

    private function error(string $message): void
    {
        echo PHP_EOL . '[ERROR] ' . $message . PHP_EOL . PHP_EOL;
    }
}
