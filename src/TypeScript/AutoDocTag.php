<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\UnresolvedType;

class AutoDocTag
{
    public function __construct(
        public Scope $scope,
        public TypeScriptFile $tsFile,
        public int $lineIndex,
        public string $value,
        public bool $addExportKeyword = true,
    ) {
        if (preg_match('/^(.*)\s+(\{.*\})\s*/s', $value, $matches)) {
            $this->value = $matches[1];

            $optionsString = $matches[2];
            $phpDoc = new PhpDoc('/** ' . ' */', $this->scope);

            $optionsType = $phpDoc->createUnresolvedType($phpDoc->createTypeNode('array' . $optionsString))->resolve();

            if (! $optionsType instanceof ArrayType || empty($optionsType->shape)) {
                $this->reportError('Failed to parse @autodoc tag options: ' . $optionsString);

                return;
            }

            foreach ($optionsType->shape as $key => $optionType) {
                $optionType = $optionType->unwrapType($this->scope->config);

                if ($key === 'omit') {
                    if ($optionType instanceof StringType && $optionType->value) {
                        $this->options[$key] = $optionType->getPossibleValues() ?? [];
                    }

                } else {
                    $this->reportError('Unknown tag option: ' . $key);
                }
            }
        }
    }

    /**
     * @var string[]
     */
    private ?array $existingStructureLines = null;

    private string $existingStructureType;
    private string $existingStructureName;

    /**
     * @var UnresolvedType[]
     */
    public array $templateTypeValues = [];

    /**
     * @var array{
     *     omit?: string[],
     * }
     */
    public array $options = [];


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


    public function reportError(string $message): void
    {
        $file = $this->tsFile->filePath;
        $line = $this->lineIndex + 1;

        echo $message . " [$file:$line]\n";
    }
}
