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


class ClassNameResolver extends NodeVisitorAbstract
{
    private ?string $namespace = null;

    /**
     * @var array<string, class-string>
     */
    public array $classAliases = [];

    /**
     * @return class-string
     */
    public function getResolvedClassName(string $name): string
    {
        [$firstPart, $remainingParts] = explode('\\', $name, 2) + [1 => ''];

        if (isset($this->classAliases[$firstPart])) {
            /** @var class-string */
            return trim($this->classAliases[$firstPart] . '\\' . $remainingParts, '\\');
        }

        /** @var class-string */
        return $this->getNamespacePrefix() . $name;
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
                        if (! $this->isClassImport($stmt, $use)) {
                            continue;
                        }

                        /** @var class-string */
                        $className = $use->name->toString();

                        $alias = $use->alias ?? PhpClass::basename($className);

                        $this->classAliases[(string) $alias] = $className;
                    }

                } else if ($stmt instanceof GroupUse) {
                    foreach ($stmt->uses as $use) {
                        if (! $this->isClassImport($stmt, $use)) {
                            continue;
                        }

                        $className = PhpClass::removeLeadingBackslash($stmt->prefix . '\\' . $use->name->toString());

                        $alias = $use->alias ?? PhpClass::basename($className);

                        $this->classAliases[(string) $alias] = $className;
                    }
                }
            }
        }

        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    private function isClassImport(Use_|GroupUse $statement, UseItem $use): bool
    {
        $type = $use->type === Use_::TYPE_UNKNOWN ? $statement->type : $use->type;

        return $type === Use_::TYPE_NORMAL;
    }
}
