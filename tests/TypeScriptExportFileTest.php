<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeScriptFile;
use AutoDoc\TypeScript\TypeScriptGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeScriptExportFileTest extends TestCase
{
    use LoadsConfig;

    private string $temporaryDirectory;

    /**
     * @var string[]
     */
    private array $generatedFiles = [];

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/autodoc-typescript-' . uniqid();
        mkdir($this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $generatedFile) {
            if (file_exists($generatedFile)) {
                unlink($generatedFile);
            }
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
    }

    #[Test]
    public function itUsesTheUniqueExportedNameForForwardReferences(): void
    {
        $contents = $this->generateSharedFile(<<<'TS'
        /** @autodoc AutoDoc\Tests\TestProject\Entities\TypeScriptReferencingType { mode: 'isolated_export' } */
        /** @autodoc AutoDoc\Tests\TestProject\Entities\TypeScriptReferencedType { mode: 'isolated_export', as: 'ReferencedType' } */
        TS);

        $this->assertStringContainsString(<<<'TS'
        export type TypeScriptReferencingType = {
            referenced: ReferencedType
        }

        export type ReferencedType = {
            id: number
        }
        TS, $contents);
    }

    #[Test]
    public function itKeepsReferencesInlineWhenAClassHasMultipleExportedNames(): void
    {
        $contents = $this->generateSharedFile(<<<'TS'
        /** @autodoc AutoDoc\Tests\TestProject\Entities\TypeScriptReferencingType { mode: 'isolated_export' } */
        /** @autodoc AutoDoc\Tests\TestProject\Entities\TypeScriptReferencedType { mode: 'isolated_export', as: 'FirstReferencedType' } */
        /** @autodoc AutoDoc\Tests\TestProject\Entities\TypeScriptReferencedType { mode: 'isolated_export', as: 'SecondReferencedType' } */
        TS);

        $this->assertStringContainsString(<<<'TS'
        export type TypeScriptReferencingType = {
            referenced: {
                id: number
            }
        }
        TS, $contents);
    }

    private function generateSharedFile(string $input): string
    {
        $config = self::loadConfig();
        $config->data['typescript']['path_prefixes'] = fn () => ['@' => $this->temporaryDirectory];
        $config->data['typescript']['modes']['isolated_export'] = [
            'save_types_in_single_file' => '@/types.ts',
        ];

        $generator = new TypeScriptGenerator;
        $tsFile = new TypeScriptFile(null, $generator);
        $tsFile->lines = explode("\n", $input);
        $tsFile->processAutodocTags(new Scope($config));
        $generator->overwriteGeneratedFiles();

        $generatedFile = $this->temporaryDirectory . '/types.ts';
        $this->generatedFiles[] = $generatedFile;
        $contents = file_get_contents($generatedFile);

        $this->assertIsString($contents);

        return str_replace("\r\n", "\n", $contents);
    }
}
