<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use Exception;

class TypeScriptFile
{
    public function __construct(
        public ?string $filePath = null,
        private ?TypeScriptGenerator $generator = null,
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
        $lineIndex = $startLineIndex;
        $currentTag = '';
        $tagFinished = true;

        while (isset($this->lines[$lineIndex])) {
            if (! $tagFinished) {
                $indexOfCommentEnd = strpos($this->lines[$lineIndex], '*/');

                if ($indexOfCommentEnd !== false) {
                    $tagFinished = true;
                    $currentTag .= "\n" . substr($this->lines[$lineIndex], 0, $indexOfCommentEnd);

                } else {
                    $currentTag .= "\n" . $this->lines[$lineIndex];
                    $lineIndex++;

                    continue;
                }
            }

            if (preg_match('/^[\s\*\/]*@autodoc\s+(.*?)\s*[\s\*\/]*$/', $this->lines[$lineIndex], $matches)) {
                $currentTag = $matches[1];
                $tagFinished = str_contains($this->lines[$lineIndex], '*/');
            }

            if ($currentTag && $tagFinished) {
                $tag = new AutoDocTag(
                    scope: $scope,
                    tsFile: $this,
                    lineIndex: $lineIndex,
                    value: $currentTag,
                    addExportKeyword: $this->filePath && ! str_ends_with($this->filePath, '.vue'),
                );

                if ($tag->addExportKeyword) {
                    $indent = $tag->getDeclarationIndent();

                    if ($indent) {
                        $tag->addExportKeyword = false;
                    }
                }

                return $tag;
            }

            $lineIndex++;
        }

        return null;
    }


    public function processAutodocTags(Scope $scope): int
    {
        $generator = $this->generator ?? new TypeScriptGenerator($scope->config);

        $processedTags = 0;
        $tag = $this->findFirstAutodocTag($scope);

        while ($tag !== null) {
            $newDeclarationLines = $generator->generateTypeScriptDeclaration($tag);
            $commentLinesAfterTag = $this->getCommentLinesAfterTag($tag);

            $newDeclarationIndex = $tag->lineIndex + count($commentLinesAfterTag) + 1;

            $this->lines = array_merge(
                array_slice($this->lines, 0, $newDeclarationIndex),
                $newDeclarationLines,
                array_slice($this->lines, $newDeclarationIndex + ($tag->hasExistingDeclaration() ? count($tag->getExistingStructureLines()) : 0)),
            );

            $tag = $this->findFirstAutodocTag($scope, $newDeclarationIndex + count($newDeclarationLines));

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

        $lineCount = $this->getStructureLineCount($startIndex);

        return array_slice($this->lines, $startIndex, $lineCount);
    }


    private function getStructureLineCount(int $startIndex): int
    {
        if (! isset($this->lines[$startIndex])) {
            return 0;
        }

        if (trim($this->lines[$startIndex]) === '') {
            return 0;
        }

        if (! str_contains($this->lines[$startIndex], '{')) {
            return 1;
        }

        $braceLevel = 0;
        $inBlockComment = false;
        $inString = false;
        $stringChar = '';
        $lineCount = 0;

        for ($lineIndex = $startIndex; $lineIndex < count($this->lines); $lineIndex++) {
            $line = $this->lines[$lineIndex];
            $lineCount++;

            $chars = str_split($line);

            for ($charIndex = 0; $charIndex < count($chars); $charIndex++) {
                $char = $chars[$charIndex];
                $nextChar = $chars[$charIndex + 1] ?? '';

                if ($inBlockComment) {
                    if ($char === '*' && $nextChar === '/') {
                        $inBlockComment = false;
                        $charIndex++;
                    }

                    continue;
                }

                if ($inString) {
                    if ($char === '\\') {
                        $charIndex++; // skip escaped character

                    } else if ($char === $stringChar) {
                        $inString = false;
                    }

                    continue;
                }

                // Start of string
                if ($char === '"' || $char === "'" || $char === '`') {
                    $inString = true;
                    $stringChar = $char;

                    continue;
                }

                // Start of block comment
                if ($char === '/' && $nextChar === '*') {
                    $inBlockComment = true;
                    $charIndex++;

                    continue;
                }

                // Line comment
                if ($char === '/' && $nextChar === '/') {
                    break;
                }

                if ($char === '{') {
                    $braceLevel++;

                } else if ($char === '}') {
                    $braceLevel--;
                }
            }

            if ($braceLevel === 0) {
                return $lineCount;
            }
        }

        return $lineCount;
    }


    /**
     * @return string[]
     */
    public function getCommentLinesBeforeTag(AutoDocTag $tag): array
    {
        if (str_contains($this->lines[$tag->lineIndex], '/*')) {
            return [];
        }

        $commentLines = [];

        for ($i = $tag->lineIndex - 1; $i >= 0; $i--) {
            $currentLine = $this->lines[$i];

            $commentLines[] = $currentLine;

            if (str_contains($currentLine, '/*')) {
                break;
            }
        }

        return array_reverse($commentLines);
    }



    /**
     * @return string[]
     */
    public function getCommentLinesAfterTag(AutoDocTag $tag): array
    {
        if (str_contains($this->lines[$tag->lineIndex], '*/')) {
            return [];
        }

        $commentLines = [];

        for ($i = $tag->lineIndex + 1; $i < count($this->lines); $i++) {
            $currentLine = $this->lines[$i];

            $commentLines[] = $currentLine;

            if (str_contains($currentLine, '*/')) {
                break;
            }
        }

        return $commentLines;
    }
}
