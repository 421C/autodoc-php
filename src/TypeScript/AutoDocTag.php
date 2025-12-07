<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedType;
use Exception;

/**
 * @phpstan-import-type TypeScriptConfig from Config
 */
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

                    } else {
                        $this->reportError('The value of `omit` tag must be a string or union of strings.');
                    }

                } else if ($key === 'only') {
                    if ($optionType instanceof StringType && $optionType->value) {
                        $this->options[$key] = $optionType->getPossibleValues() ?? [];

                    } else {
                        $this->reportError('The value of `only` tag must be a string or union of strings.');
                    }

                } else if ($key === 'from') {
                    if ($optionType instanceof StringType && is_string($optionType->value)) {
                        if (class_exists($optionType->value) || interface_exists($optionType->value) || trait_exists($optionType->value)) {
                            $this->options[$key] = $optionType->value;
                            $this->scope = $this->scope->createChildScope($optionType->value);

                        } else {
                            $this->reportError('The value of `from` tag is not a valid class name.');
                        }

                    } else if (($optionType instanceof ObjectType || $optionType instanceof ArrayType) && $optionType->className) {
                        $this->options[$key] = $optionType->className;
                        $this->scope = $this->scope->createChildScope($optionType->className);

                    } else {
                        $this->reportError('The value of `from` tag must be a string or a class type identifier.');
                    }

                } else if ($key === 'with') {
                    if ($optionType instanceof ObjectType && $optionType->properties) {
                        $this->options[$key] = $optionType->properties;

                    } else if ($optionType instanceof ArrayType && $optionType->shape) {
                        $this->options[$key] = $optionType->shape;

                    } else {
                        $this->reportError('The value of `with` tag must be an object or array shape.');
                    }

                } else if ($key === 'mode') {
                    if ($optionType instanceof StringType && is_string($optionType->value)) {
                        $this->options[$key] = $optionType->value;

                    } else {
                        $this->reportError('The value of `mode` tag must be a string.');
                    }

                } else if ($key === 'as') {
                    if ($optionType instanceof StringType && is_string($optionType->value)) {
                        $this->options[$key] = $optionType->value;

                    } else {
                        $this->reportError('The value of `as` tag must be a string.');
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
     *     only?: string[],
     *     from?: class-string,
     *     with?: array<int|string, Type>,
     *     mode?: string,
     *     as?: string,
     * }
     */
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


    public function reportError(string $message): void
    {
        $file = $this->tsFile->filePath;
        $line = $this->lineIndex + 1;

        throw new Exception($message . ($file ? " [$file:$line]" : ''));
    }
}
