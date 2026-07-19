<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Ast;

use AutoDoc\Analyzer\PhpClass;
use Override;
use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;


class SymbolNameResolver extends NodeVisitorAbstract
{
    private ?string $namespace = null;

    /**
     * @var array<string, class-string>
     */
    public array $classAliases = [];

    /**
     * @var array<string, string>
     */
    public array $functionAliases = [];

    /**
     * @var array<string, string>
     */
    public array $constantAliases = [];

    /**
     * @return class-string
     */
    public function getResolvedClassName(string $name): string
    {
        /** @var class-string */
        return $this->resolveNamespacedName($name);
    }

    public function getResolvedFunctionName(string $name): string
    {
        if (str_contains($name, '\\')) {
            return $this->resolveNamespacedName($name);
        }

        if (isset($this->functionAliases[$name])) {
            return $this->functionAliases[$name];
        }

        $namespacedName = $this->getNamespacePrefix() . $name;

        return function_exists($namespacedName) ? $namespacedName : $name;
    }

    public function getResolvedConstantName(string $name): string
    {
        if (str_contains($name, '\\')) {
            return $this->resolveNamespacedName($name);
        }

        if (isset($this->constantAliases[$name])) {
            return $this->constantAliases[$name];
        }

        $namespacedName = $this->getNamespacePrefix() . $name;

        return defined($namespacedName) ? $namespacedName : $name;
    }

    public function getNamespacePrefix(): string
    {
        if (! $this->namespace) {
            return '';
        }

        return $this->namespace . '\\';
    }

    /**
     * @return NodeVisitor::DONT_TRAVERSE_CHILDREN
     */
    #[Override]
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace ??= $node->name?->toString();

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Use_) {
                    foreach ($stmt->uses as $use) {
                        $this->collectImport($use, $stmt->type, $use->name->toString());
                    }

                } else if ($stmt instanceof GroupUse) {
                    foreach ($stmt->uses as $use) {
                        $this->collectImport(
                            use: $use,
                            statementType: $stmt->type,
                            importedName: PhpClass::removeLeadingBackslash($stmt->prefix . '\\' . $use->name->toString()),
                        );
                    }
                }
            }
        }

        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    private function collectImport(UseItem $use, int $statementType, string $importedName): void
    {
        $type = $use->type === Use_::TYPE_UNKNOWN ? $statementType : $use->type;

        $alias = (string) ($use->alias ?? PhpClass::basename($importedName));

        if ($type === Use_::TYPE_FUNCTION) {
            $this->functionAliases[$alias] = $importedName;

        } else if ($type === Use_::TYPE_CONSTANT) {
            $this->constantAliases[$alias] = $importedName;

        } else {
            /** @var class-string $importedName */
            $this->classAliases[$alias] = $importedName;
        }
    }

    /**
     * Qualified names resolve their leading segment through class and
     * namespace imports, matching PHP name resolution for `Alias\symbol`.
     */
    private function resolveNamespacedName(string $name): string
    {
        [$firstPart, $remainingParts] = explode('\\', $name, 2) + [1 => ''];

        if (isset($this->classAliases[$firstPart])) {
            return trim($this->classAliases[$firstPart] . '\\' . $remainingParts, '\\');
        }

        return $this->getNamespacePrefix() . $name;
    }
}
