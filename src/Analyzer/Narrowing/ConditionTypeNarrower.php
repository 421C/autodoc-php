<?php declare(strict_types=1);

namespace AutoDoc\Analyzer\Narrowing;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\Type;
use PhpParser\Node;

final readonly class ConditionTypeNarrower
{
    public function __construct(
        private ConditionFactExtractor $factExtractor = new ConditionFactExtractor,
    ) {}


    public function narrow(
        Node $conditionNode,
        Scope $scope,
        Target $target,
        Type $baseType,
        bool $negated = false,
    ): ?Type {

        $applier = new TypeNarrowingApplier($scope);
        $narrowedType = $baseType;
        $matched = false;

        foreach ($this->factExtractor->extract($conditionNode, $scope, $negated) as $fact) {
            $relativePath = $this->relativeNarrowingPath($target, $fact->target);

            if ($relativePath === null) {
                continue;
            }

            $matched = true;
            $narrowedType = $applier->applyPath($narrowedType, $relativePath, $fact->narrowing);
        }

        return $matched ? $narrowedType : null;
    }

    /**
     * Return the attribute path within `$target` that an extracted narrowing
     * applies to. For example, narrowing `$item['type']` while targeting `$item`
     * yields `['type']`; narrowing `$item` while targeting `$item` yields `[]`.
     *
     * @return list<int|string>|null
     */
    private function relativeNarrowingPath(Target $target, Target $narrowedTarget): ?array
    {
        if ($target->baseVar !== $narrowedTarget->baseVar) {
            return null;
        }

        if (count($target->path) > count($narrowedTarget->path)) {
            return null;
        }

        foreach ($target->path as $index => $pathPart) {
            if ($narrowedTarget->path[$index] !== $pathPart) {
                return null;
            }
        }

        return array_slice($narrowedTarget->path, count($target->path));
    }
}
