<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use Exception;

/**
 * @phpstan-import-type TypeScriptConfig from Config
 * @phpstan-import-type AutoDocTagOptions from ParsedAutoDocTag
 */
class AutoDocTag
{
    public function __construct(
        public Scope $scope,
        public TypeScriptFile $tsFile,
        public int $lineIndex,
        public string $value,
        public bool $addExportKeyword = true,
        AutoDocTagParser $parser = new AutoDocTagParser,
    ) {
        try {
            $parsedTag = $parser->parse($value, $scope);

        } catch (Exception $exception) {
            $this->throwError($exception->getMessage());
        }

        $this->scope = $parsedTag->scope;
        $this->value = $parsedTag->value;
        $this->options = $parsedTag->options;
    }

    /**
     * @var string[]
     */
    private ?array $existingStructureLines = null;

    private string $existingStructureType;
    private string $existingStructureName;

    /** @var AutoDocTagOptions */
    public array $options = [];

    /**
     * @var TypeScriptConfig
     */
    private array $config;


    public function hasExistingDeclaration(): bool
    {
        $this->parseExistingDeclaration();

        return isset($this->existingStructureType);
    }

    /**
     * @return string[]
     */
    public function getExistingStructureLines(): array
    {
        if ($this->existingStructureLines === null) {
            $this->existingStructureLines = $this->tsFile->getStructureAfterTag($this);
        }

        return $this->existingStructureLines;
    }


    public function getExistingStructureName(): ?string
    {
        if (isset($this->existingStructureName)) {
            return $this->existingStructureName;
        }

        $this->parseExistingDeclaration();

        return $this->existingStructureName ?? null;
    }


    public function getExistingStructureType(): ?string
    {
        if (isset($this->existingStructureType)) {
            return $this->existingStructureType;
        }

        $this->parseExistingDeclaration();

        return $this->existingStructureType ?? null;
    }


    private function parseExistingDeclaration(): void
    {
        if (isset($this->existingStructureType)) {
            return;
        }

        $existingStructureLines = $this->getExistingStructureLines();

        if (! isset($existingStructureLines[0])) {
            return;
        }

        if (preg_match('/^\s*(export\s+)?(enum|interface|type)\s+([^\s]+)\s*=?/', $existingStructureLines[0], $matches)) {
            $this->existingStructureType = $matches[2];
            $this->existingStructureName = $matches[3];
            $this->addExportKeyword = str_contains($matches[1], 'export');
        }
    }


    public function getDeclarationIndent(): string
    {
        if ($this->hasExistingDeclaration()) {
            preg_match('/^\s*/', $this->getExistingStructureLines()[0] ?? '', $matches);

            return $matches[0] ?? '';
        }

        $firstLineOfComment = $this->tsFile->getCommentLinesBeforeTag($this)[0] ?? $this->tsFile->lines[$this->lineIndex];

        preg_match('/^\s*/', $firstLineOfComment, $matches);

        return $matches[0] ?? '';
    }


    /**
     * @template K of key-of<TypeScriptConfig>
     * @param K|null $key
     * @return ($key is null ? TypeScriptConfig : TypeScriptConfig[K])
     */
    public function getConfig(?string $key = null): mixed
    {
        if (! isset($this->config)) {
            $this->config = $this->scope->config->getTypeScriptConfig($this->options['mode'] ?? null);
        }

        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? null;
    }


    public function throwError(string $message): never
    {
        $file = $this->tsFile->filePath;
        $line = $this->lineIndex + 1;

        throw new Exception($message . ($file ? " [$file:$line]" : ''));
    }
}
