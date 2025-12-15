<?php declare(strict_types=1);

namespace AutoDoc\Commands;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\TypeScript\RoutesExporter;
use AutoDoc\TypeScript\TypeScriptFile;
use AutoDoc\TypeScript\TypeScriptGenerator;
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
     * } | array{
     *     filePath: string,
     *     exportedRequests: int,
     *     exportedResponses: int,
     * } | array{
     *     error: Throwable|string,
     * }>
     */
    public function run(
        ?string $workingDirectory = null,
        ?array $fileExtensions = null,
    ): iterable {
        $workingDirectory ??= $this->config->data['typescript']['working_directory']
            ?? throw new Exception('Working directory not specified');

        $fileExtensions ??= $this->config->data['typescript']['file_extensions'] ?? ['ts', 'tsx', 'vue'];

        if (isset($this->config->data['memory_limit'])) {
            ini_set('memory_limit', $this->config->data['memory_limit']);
        }

        yield from $this->exportRequestsAndResponses();

        yield from $this->updateAutoDocTagStructures($workingDirectory, $fileExtensions);
    }

    /**
     * @return iterable<array{
     *     filePath: string,
     *     exportedRequests: int,
     *     exportedResponses: int,
     * }>
     */
    private function exportRequestsAndResponses(): iterable
    {
        foreach ($this->config->data['typescript']['export_http_requests_and_responses'] ?? [] as $filePath => $options) {
            yield (new RoutesExporter($this->config, $filePath))->export();
        }
    }

    /**
     * @param string[] $fileExtensions
     *
     * @return iterable<array{
     *     filePath: string,
     *     processedTags: int,
     * } | array{
     *     error: Throwable|string,
     * }>
     */
    private function updateAutoDocTagStructures(string $workingDirectory, array $fileExtensions): iterable
    {
        $files = $this->getFiles($workingDirectory, $fileExtensions);

        $scope = new Scope($this->config);
        $generator = new TypeScriptGenerator;

        foreach ($files as $filePath) {
            try {
                $tsFile = new TypeScriptFile($filePath, $generator);

            } catch (Throwable $exception) {
                yield [
                    'error' => $exception,
                ];

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

        $generator->overwriteGeneratedFiles();
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
