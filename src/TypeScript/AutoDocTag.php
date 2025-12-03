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
     * @param K $key
     * @return (K is 'path_prefixes' ? iterable<string, string> : TypeScriptConfig[K])
     */
    public function getConfig(string $key): mixed
    {
        if (! isset($this->config)) {
            $this->config = $this->scope->config->getTypeScriptConfig($this->options['mode'] ?? null);
        }

        $defaults = [
            'working_directory' => null,
            'file_extensions' => ['ts', 'tsx', 'vue'],
            'indent' => '    ',
            'string_quote' => "'",
            'add_semicolons' => false,
            'show_values_for_scalar_types' => true,
            'save_types_in_single_file' => null,
            'modes' => [],
            'path_prefixes' => fn () => [],
        ];

        $value = $this->config[$key] ?? $defaults[$key];

        if ($key === 'path_prefixes') {
            if (is_string($value)) {
                if (class_exists($value) && method_exists($value, '__invoke')) {
                    $value = (new $value)(...);

                } else {
                    throw new Exception("Error: path_prefixes in autodoc config is not an invokable class name or callable. '$value' given.");
                }
            }

            if (! is_callable($value)) {
                $type = gettype($value);

                throw new Exception("Error: path_prefixes in autodoc config is of type $type. It must be an invokable class name or a function of type `callable(Config \$config): iterable<string, string>`.");
            }
        }

        if (is_callable($value)) {
            return $value($this->scope->config);
        }

        return $value;
    }


    public function reportError(string $message): void
    {
        $file = $this->tsFile->filePath;
        $line = $this->lineIndex + 1;

        throw new Exception($message . ($file ? " [$file:$line]" : ''));
    }
}
