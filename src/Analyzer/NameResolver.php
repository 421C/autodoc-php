<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use Override;
use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;


class NameResolver extends NodeVisitorAbstract
{
    private ?string $namespace = null;

    /**
     * @var array<string, class-string>
     */
    public array $aliases = [];

    /**
     * @return class-string
     */
    public function getResolvedClassName(string $name): string
    {
        [$firstPart, $remainingParts] = explode('\\', $name, 2) + [1 => ''];

        if (isset($this->aliases[$firstPart])) {
            /** @var class-string */
            $className = trim($this->aliases[$firstPart] . '\\' . $remainingParts, '\\');

        } else {
            /** @var class-string */
            $className = $this->getNamespacePrefix() . $name;
        }

        return $className;
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
                if ($stmt instanceof Node\Stmt\Use_) {
                    foreach ($stmt->uses as $use) {
                        /** @var class-string */
                        $className = $use->name->toString();

                        $alias = $use->alias ?? PhpClass::basename($className);

                        $this->aliases[(string) $alias] = $className;
                    }

                } else if ($stmt instanceof Node\Stmt\GroupUse) {
                    foreach ($stmt->uses as $use) {
                        $className = PhpClass::removeLeadingBackslash($stmt->prefix . '\\' . $use->name->toString());

                        $alias = $use->alias ?? PhpClass::basename($className);

                        $this->aliases[(string) $alias] = $className;
                    }
                }
            }
        }

        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }
}
