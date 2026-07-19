<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\Type;

/**
 * @phpstan-import-type TypeScriptConfig from Config
 * @phpstan-import-type AutoDocTagOptions from ParsedAutoDocTag
 */
class TypeScriptRenderContext
{
    /**
     * @param TypeScriptConfig $config
     * @param array<string, string> $namedTypes
     * @param AutoDocTagOptions $rootOptions
     */
    public function __construct(
        public readonly Scope $scope,
        public readonly array $config,
        public readonly string $baseIndent = '',
        public readonly bool $isRootLevel = false,
        public readonly array $namedTypes = [],
        public readonly array $rootOptions = [],
    ) {}

    public function nested(?string $baseIndent = null): self
    {
        return new self(
            scope: $this->scope,
            config: $this->config,
            baseIndent: $baseIndent ?? $this->baseIndent,
            namedTypes: $this->namedTypes,
            rootOptions: $this->rootOptions,
        );
    }
}
