<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;

class AutoDocTag
{
    public function __construct(
        public Scope $scope,
        public TypeScriptFile $tsFile,
        public int $lineIndex,

        /**
         * @var string[]
         */
        public array $arguments,
    ) {}

    /**
     * @var string[]
     */
    private ?array $existingStructureLines = null;

    private string $existingStructureType;
    private string $existingStructureName;

    public bool $addExportKeyword = true;


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
     * @return string[]
     */
    public function generateTypeScriptDeclaration(): array
    {
        return (new TypeScriptGenerator($this->scope->config))->generateTypeScriptDeclaration($this);
    }
}
