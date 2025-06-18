<?php declare(strict_types=1);

namespace AutoDoc\Commands;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\TypeScript\TypeScriptFile;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Read `@autodoc` tags in typescript code and update typescript types from PHP structures.
 */
class UpdateTypeScriptStructures
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * @param string[] $fileExtensions
     *
     * @return iterable<array{
     *     filePath: string,
     *     processedTags: int,
     * }>
     */
    public function run(
        ?string $workingDirectory = null,
        ?array $fileExtensions = null,
    ): iterable {
        $workingDirectory ??= $this->config->data['typescript']['working_directory']
            ?? throw new Exception('Working directory not specified');

        $fileExtensions ??= $this->config->data['typescript']['file_extensions'] ?? ['ts', 'tsx', 'vue'];

        $files = $this->getFiles($workingDirectory, $fileExtensions);

        $scope = new Scope($this->config);

        foreach ($files as $filePath) {
            try {
                $tsFile = new TypeScriptFile($filePath);

            } catch (Throwable $e) {
                $this->log($e->getMessage());

                continue;
            }

            $processedTags = $tsFile->processAutodocTags($scope);

            if ($processedTags > 0) {
                $tsFile->writeLines();

                yield [
                    'filePath' => $filePath,
                    'processedTags' => $processedTags,
                ];
            }
        }
    }

    /**
     * @param string[] $fileExtensions
     * @return string[]
     */
    private function getFiles(string $directory, array $fileExtensions): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), $fileExtensions)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }


    protected function log(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
