<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use Exception;

class TypeScriptExportFile
{
    public function __construct(
        public readonly string $filePath,
        ?GeneratedTypeScriptFile $generatedFile = null,
    ) {
        $this->generatedFile = $generatedFile ?? new GeneratedTypeScriptFile($filePath);
    }

    private readonly GeneratedTypeScriptFile $generatedFile;

    /**
     * @var array<string, TypeScriptDeclaration>
     */
    private array $declarations = [];

    /**
     * @var array<string, list<string>>
     */
    private array $typeNamesByClass = [];

    public function add(TypeScriptDeclaration $declaration): void
    {
        if (isset($this->declarations[$declaration->name])) {
            throw new Exception('Type "' . $declaration->name . '" is already exported in file "' . $this->filePath . '". Use `as` option to export type with a different name.');
        }

        $this->declarations[$declaration->name] = $declaration;

        if ($declaration->className !== null) {
            $className = ltrim($declaration->className, '\\');
            $this->typeNamesByClass[$className][] = $declaration->name;
        }
    }

    public function write(): void
    {
        $this->generatedFile->write($this->renderBody());
    }

    private function renderBody(): string
    {
        $namedTypes = [];

        foreach ($this->typeNamesByClass as $className => $typeNames) {
            if (count($typeNames) === 1) {
                $namedTypes[$className] = $typeNames[0];
            }
        }

        $renderedDeclarations = array_map(
            fn (TypeScriptDeclaration $declaration): string => $this->normalizeIndent($declaration->render($namedTypes)),
            $this->declarations,
        );

        return implode("\n\n", $renderedDeclarations);
    }

    private function normalizeIndent(string $text): string
    {
        $lines = explode("\n", $text);
        $indents = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^([ \t]+)(?=\S)/', $line, $matches)) {
                $indents[] = $matches[1];

            } else {
                return $text;
            }
        }

        if (empty($indents)) {
            return $text;
        }

        $commonIndent = $indents[0];

        foreach ($indents as $indent) {
            while (! str_starts_with($indent, $commonIndent)) {
                $commonIndent = substr($commonIndent, 0, -1);
            }

            if ($commonIndent === '') {
                return $text;
            }
        }

        return preg_replace('/^' . preg_quote($commonIndent, '/') . '/m', '', $text) ?? $text;
    }
}
