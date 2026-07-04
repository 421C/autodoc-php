<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

class TypeScriptSourceScanner
{
    /**
     * @param string[] $lines
     * @return array{lineIndex: int, value: string}|null
     */
    public function findAutoDocTag(array $lines, int $startLineIndex = 0): ?array
    {
        $lineIndex = $startLineIndex;
        $currentTag = '';
        $tagFinished = true;

        while (isset($lines[$lineIndex])) {
            if (! $tagFinished) {
                $indexOfCommentEnd = strpos($lines[$lineIndex], '*/');

                if ($indexOfCommentEnd !== false) {
                    $tagFinished = true;
                    $currentTag .= "\n" . substr($lines[$lineIndex], 0, $indexOfCommentEnd);

                } else {
                    $currentTag .= "\n" . $lines[$lineIndex];
                    $lineIndex++;

                    continue;
                }
            }

            if (preg_match('/^[\s\*\/]*@autodoc\s+(.*?)\s*[\s\*\/]*$/', $lines[$lineIndex], $matches)) {
                $currentTag = $matches[1];
                $tagFinished = str_contains($lines[$lineIndex], '*/');
            }

            if ($currentTag && $tagFinished) {
                return [
                    'lineIndex' => $lineIndex,
                    'value' => $currentTag,
                ];
            }

            $lineIndex++;
        }

        return null;
    }

    /**
     * @param string[] $lines
     */
    public function getStructureLineCount(array $lines, int $startIndex): int
    {
        if (! isset($lines[$startIndex]) || trim($lines[$startIndex]) === '') {
            return 0;
        }

        if (! str_contains($lines[$startIndex], '{')) {
            return 1;
        }

        $braceLevel = 0;
        $inBlockComment = false;
        $inString = false;
        $stringChar = '';
        $lineCount = 0;
        $counter = count($lines);

        for ($lineIndex = $startIndex; $lineIndex < $counter; $lineIndex++) {
            $line = $lines[$lineIndex];
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
                        $charIndex++;

                    } else if ($char === $stringChar) {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"' || $char === "'" || $char === '`') {
                    $inString = true;
                    $stringChar = $char;

                    continue;
                }

                if ($char === '/' && $nextChar === '*') {
                    $inBlockComment = true;
                    $charIndex++;

                    continue;
                }

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
     * @param string[] $lines
     * @return string[]
     */
    public function getCommentLinesBeforeTag(array $lines, int $lineIndex): array
    {
        if (str_contains($lines[$lineIndex], '/*')) {
            return [];
        }

        $commentLines = [];

        for ($index = $lineIndex - 1; $index >= 0; $index--) {
            $currentLine = $lines[$index];
            $commentLines[] = $currentLine;

            if (str_contains($currentLine, '/*')) {
                break;
            }
        }

        return array_reverse($commentLines);
    }

    /**
     * @param string[] $lines
     * @return string[]
     */
    public function getCommentLinesAfterTag(array $lines, int $lineIndex): array
    {
        if (str_contains($lines[$lineIndex], '*/')) {
            return [];
        }

        $commentLines = [];
        $counter = count($lines);

        for ($index = $lineIndex + 1; $index < $counter; $index++) {
            $currentLine = $lines[$index];
            $commentLines[] = $currentLine;

            if (str_contains($currentLine, '*/')) {
                break;
            }
        }

        return $commentLines;
    }
}
