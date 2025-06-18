<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use Exception;

class TypeScriptFile
{
    public function __construct(
        public ?string $filePath = null,
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

        while (isset($this->lines[$lineIndex])) {
            if (preg_match('/^[\s\*\/]*@autodoc\s+(.*?)\s*[\s\*\/]*$/', $this->lines[$lineIndex], $matches)) {
                return new AutoDocTag(
                    scope: $scope,
                    tsFile: $this,
                    lineIndex: $lineIndex,
                    arguments: preg_split('/\s+/', $matches[1], -1, PREG_SPLIT_NO_EMPTY) ?: [],
                );
            }

            $lineIndex++;
        }

        return null;
    }


    public function processAutodocTags(Scope $scope): int
    {
        $processedTags = 0;
        $tag = $this->findFirstAutodocTag($scope);

        while ($tag !== null) {
            $newDeclarationLines = $tag->generateTypeScriptDeclaration();
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
        $braceLevel = 0;
        $inBlockComment = false;
        $inString = false;
        $stringChar = '';
        $started = false;
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
                    $started = true;

                } else if ($char === '}') {
                    $braceLevel--;

                    if ($braceLevel === 0 && $started) {
                        return $lineCount;
                    }
                }
            }

            // Handle single-line type declarations like: `type A = string;`
            if (!$started && preg_match('/^\s*(type|interface|enum)\b/', $line)) {
                if (! str_contains($line, '{')) {
                    return $lineCount;
                }
            }

            // If started but no braces at all (single line definition without block)
            if ($started && $braceLevel === 0) {
                return $lineCount - 1;
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
