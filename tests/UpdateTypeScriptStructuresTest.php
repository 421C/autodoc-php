<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Commands\UpdateTypeScriptStructures;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

final class UpdateTypeScriptStructuresTest extends TestCase
{
    use LoadsConfig;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/autodoc-ts-sync-' . uniqid();
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/*.ts') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    #[Test]
    public function itReportsAFailedFileAndContinuesWithTheRemainingOnes(): void
    {
        $brokenFile = $this->temporaryDirectory . '/broken.ts';
        $validFile = $this->temporaryDirectory . '/valid.ts';

        file_put_contents($brokenFile, "/** @autodoc GET /missing */\n");
        file_put_contents($validFile, "/** @autodoc string */\ntype Valid = number\n");

        $config = self::loadConfig();
        $config->data['typescript']['export_http_requests_and_responses'] = [];

        $results = iterator_to_array(
            new UpdateTypeScriptStructures($config)->run($this->temporaryDirectory),
            preserve_keys: false,
        );

        $errors = array_column($results, 'error');
        $updatedFiles = array_column($results, 'filePath');

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(Throwable::class, $errors[0]);
        $this->assertStringContainsString('Route "GET /missing" not found', $errors[0]->getMessage());
        $this->assertStringContainsString('broken.ts:1', $errors[0]->getMessage());

        $updatedFiles = array_map(static fn (string $path): string => str_replace('\\', '/', $path), $updatedFiles);

        $this->assertSame([str_replace('\\', '/', $validFile)], $updatedFiles);
        $this->assertSame("/** @autodoc string */\ntype Valid = string\n", file_get_contents($validFile));
        $this->assertSame("/** @autodoc GET /missing */\n", file_get_contents($brokenFile));
    }
}
