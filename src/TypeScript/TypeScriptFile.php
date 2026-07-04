<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use Exception;

class TypeScriptFile
{
    public function __construct(
        public ?string $filePath,
        private readonly TypeScriptGenerator $generator,
        private readonly TypeScriptSourceScanner $sourceScanner = new TypeScriptSourceScanner,
    ) {
        if ($this->filePath) {
            $this->lines = $this->readLines();
        }
    }

    /**
     * @var string[]
     */
    public array $lines;

    /**
     * @return string[]
     */
    public function readLines(): array
    {
        if (! $this->filePath) {
            throw new Exception('TypeScript file path not specified');
        }

        $contents = file_get_contents($this->filePath);

        if ($contents === false) {
            throw new Exception('Failed to read TypeScript file: ' . $this->filePath);
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $contents));

        return $lines;
    }

    public function writeLines(): void
    {
        if (! $this->filePath) {
            throw new Exception('TypeScript file path not specified');
        }

        file_put_contents($this->filePath, implode("\n", $this->lines));
    }


    public function findFirstAutodocTag(Scope $scope, int $startLineIndex = 0): ?AutoDocTag
    {
        $tagMatch = $this->sourceScanner->findAutoDocTag($this->lines, $startLineIndex);

        if ($tagMatch === null) {
            return null;
        }

        $tag = new AutoDocTag(
            scope: $scope,
            tsFile: $this,
            lineIndex: $tagMatch['lineIndex'],
            value: $tagMatch['value'],
            addExportKeyword: $this->filePath && ! str_ends_with($this->filePath, '.vue'),
        );

        if ($tag->addExportKeyword && $tag->getDeclarationIndent()) {
            $tag->addExportKeyword = false;
        }

        return $tag;
    }


    public function processAutodocTags(Scope $scope): int
    {
        $processedTags = 0;
        $tag = $this->findFirstAutodocTag($scope);

        while ($tag !== null) {
            $newDeclaration = $this->generator->generateTypeScriptDeclaration($tag);
            $commentLinesAfterTag = $this->getCommentLinesAfterTag($tag);

            $newDeclarationIndex = $tag->lineIndex + count($commentLinesAfterTag) + 1;

            $this->lines = array_merge(
                array_slice($this->lines, 0, $newDeclarationIndex),
                [$newDeclaration],
                array_slice($this->lines, $newDeclarationIndex + ($tag->hasExistingDeclaration() ? count($tag->getExistingStructureLines()) : 0)),
            );

            $tag = $this->findFirstAutodocTag($scope, $newDeclarationIndex + 1);

            $processedTags++;
        }

        return $processedTags;
    }


    /**
     * @return string[]
     */
    public function getStructureAfterTag(AutoDocTag $tag): array
    {
        $commentLines = $this->getCommentLinesAfterTag($tag);

        $startIndex = $tag->lineIndex + count($commentLines) + 1;

        $lineCount = $this->sourceScanner->getStructureLineCount($this->lines, $startIndex);

        return array_slice($this->lines, $startIndex, $lineCount);
    }


    /**
     * @return string[]
     */
    public function getCommentLinesBeforeTag(AutoDocTag $tag): array
    {
        return $this->sourceScanner->getCommentLinesBeforeTag($this->lines, $tag->lineIndex);
    }

    /**
     * @return string[]
     */
    public function getCommentLinesAfterTag(AutoDocTag $tag): array
    {
        return $this->sourceScanner->getCommentLinesAfterTag($this->lines, $tag->lineIndex);
    }
}
