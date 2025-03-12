<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use PhpParser\Node;


class PhpVariable
{
    public function __construct(
        /**
         * @var array<int, array{Type, int}>
         */
        public array $assignments = [],
    ) {}


    public static function find(Node\Expr\Variable $node, Scope $scope): ?Type
    {
        if (! is_string($node->name)) {
            return null;
        }

        if ($node->name === 'this') {
            if ($scope->className) {
                return new ObjectType(className: $scope->className);
            }
        }

        $cacheKey = PhpVariable::getCacheKey($scope);

        if ($cacheKey === null || empty(PhpVariable::$cache[$cacheKey][$node->name])) {
            return null;
        }

        krsort(PhpVariable::$cache[$cacheKey][$node->name]->assignments);

        /** @var int */
        $currentLine = $node->getAttribute('startLine', 0);
        $possibleTypes = [];

        while ($currentLine > 0) {
            if (isset(PhpVariable::$cache[$cacheKey][$node->name]->assignments[$currentLine])) {
                [$varType, $depth] = PhpVariable::$cache[$cacheKey][$node->name]->assignments[$currentLine];

                $possibleTypes[] = $varType;

                if ($depth === 0) {
                    break;
                }
            }

            $currentLine--;
        }

        if (count($possibleTypes) > 1) {
            return new UnionType($possibleTypes);
        }

        return $possibleTypes[0] ?? null;
    }


    public static function assign(string $varName, int $line, Type $valueType, Scope $scope, int $depth): void
    {
        $cacheKey = PhpVariable::getCacheKey($scope);

        if ($cacheKey === null) {
            return;
        }

        if (! isset(PhpVariable::$cache[$cacheKey][$varName])) {
            PhpVariable::$cache[$cacheKey][$varName] = new PhpVariable;
        }

        PhpVariable::$cache[$cacheKey][$varName]->assignments[$line] = [$valueType, $depth];
    }


    private static function getCacheKey(Scope $scope): ?string
    {
        if ($scope->className && $scope->methodName) {
            return $scope->className . '@' . $scope->methodName;
        }

        return null;
    }


    /**
     * Valid variable cache keys:
     *     - 'path/to/file.php',
     *     - 'path/to/file.php@functionName',
     *     - 'Name\Of\Class@methodName',
     *
     * @var array<string, array<string, PhpVariable>>
     */
    private static array $cache = [];
}
