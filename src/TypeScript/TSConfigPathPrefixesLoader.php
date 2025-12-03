<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Config;
use AutoDoc\Exceptions\AutoDocException;
use Exception;
use Throwable;

class TSConfigPathPrefixesLoader
{
    /**
     * @return iterable<string, string>
     */
    public function __invoke(Config $config): iterable
    {
        try {
            $tsConfigPath = $config->data['typescript']['tsconfig_path'] ?? null;

            if (! $tsConfigPath) {
                throw new Exception('tsconfig_path is not specified in autodoc config file.');
            }

            if (! file_exists($tsConfigPath)) {
                throw new Exception("$tsConfigPath does not exist.");
            }

            $fileContents = file_get_contents($tsConfigPath);

            if ($fileContents === false) {
                throw new Exception("$tsConfigPath is not readable.");
            }


            /**
             * @var ?array{
             *     compilerOptions?: array{
             *         paths?: array<string, string[]|string|null>,
             *         baseUrl?: string,
             *     }
             * }
             */
            $data = json_decode($this->stripComments($fileContents), true);

            if (! is_array($data)) {
                throw new Exception("Failed to decode JSON from tsconfig: $tsConfigPath");
            }

            $paths = $data['compilerOptions']['paths'] ?? [];
            $baseUrl = $data['compilerOptions']['baseUrl'] ?? '.';
            $rootDir = dirname($tsConfigPath);

            foreach ($paths as $prefix => $targets) {
                if (! is_array($targets) || ! isset($targets[0])) {
                    continue;
                }

                $target = $targets[0];

                $prefix = str_ends_with($prefix, '/*') ? substr($prefix, 0, -2) : $prefix;
                $target = str_ends_with($target, '/*') ? substr($target, 0, -2) : $target;

                $targetPath = realpath("$rootDir/$baseUrl/$target");

                if ($targetPath) {
                    dump("$prefix => $targetPath");

                    yield $prefix => $targetPath;

                } else {
                    throw new Exception("Failed to resolve path prefix from tsconfig: '$target' (baseUrl: '$baseUrl')");
                }
            }

        } catch (Throwable $exception) {
            throw new AutoDocException('Failed to load TypeScript path prefixes: ', $exception);
        }
    }


    private function stripComments(string $string): string
    {
        return preg_replace('![ \t]*//.*[ \t]*[\r\n]!', '', $string) ?? '';
    }
}
