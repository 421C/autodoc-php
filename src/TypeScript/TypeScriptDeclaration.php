<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use Closure;

class TypeScriptDeclaration
{
    /**
     * @param Closure(array<string, string>): string $render
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $className,
        private readonly Closure $render,
    ) {}

    /**
     * Rendering stays deferred until every declaration in the file is known,
     * so references can use exports registered later in the scan.
     *
     * @param array<string, string> $namedTypes
     */
    public function render(array $namedTypes): string
    {
        return ($this->render)($namedTypes);
    }
}
