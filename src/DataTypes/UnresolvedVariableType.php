<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\PhpVariable;
use AutoDoc\Analyzer\Scope;
use PhpParser\Node\Stmt\If_;

class UnresolvedVariableType extends UnresolvedType
{
    public function __construct(
        public readonly PhpVariable $phpVariable,
        public readonly Scope $scope,
        public readonly int $varLine,
        public readonly int $varStartFilePos,
        public ?string $description = null,
    ) {}

    private Type $resolvedType;

    public function resolve(): Type
    {
        if (isset($this->resolvedType)) {
            return $this->resolvedType;
        }

        krsort($this->phpVariable->mutations);

        $baseType = null;
        $affectingMutations = [];
        $currentLine = $this->varLine;

        while ($currentLine >= 0) {
            if (isset($this->phpVariable->mutations[$currentLine])) {
                $mutationsOnCurrentLine = $this->phpVariable->mutations[$currentLine];

                krsort($mutationsOnCurrentLine);

                foreach ($mutationsOnCurrentLine as $mutation) {
                    if ($this->varStartFilePos > $mutation->endFilePos) {
                        $conditions = array_reverse($mutation->conditions);

                        if ($conditions) {
                            foreach ($conditions as $condition) {
                                $branches = $condition->getConditionBranches();

                                $nonBreakingBranches = array_values(array_filter(
                                    $branches,
                                    fn ($branch) => ! isset($branch['breakOutNode']),
                                ));

                                for ($branchIndex = count($branches) - 1; $branchIndex >= 0; $branchIndex--) {
                                    $branch = $branches[$branchIndex];

                                    $mutationIsInsideCurrentBranch = $mutation->startFilePos > $branch['startFilePos']
                                        && $mutation->endFilePos < $branch['endFilePos'];

                                    if (! $mutationIsInsideCurrentBranch) {
                                        continue;
                                    }

                                    $varIsInsideCurrentBranch = $this->varStartFilePos > $branch['startFilePos']
                                        && $this->varStartFilePos < $branch['endFilePos'];

                                    if ($varIsInsideCurrentBranch) {
                                        if (isset($mutation->changes['type'])) {
                                            $baseType = $mutation->changes['type'];
                                            break 4;
                                        }

                                        $affectingMutations[] = [$mutation, true];
                                        break;

                                    } else if (isset($branch['breakOutNode'])) {
                                        continue;

                                    } else {
                                        $isThisTheOnlyNonBreakingIfElseBranch = $condition->node instanceof If_
                                            && $condition->node->else
                                            && count($nonBreakingBranches) === 1;

                                        $affectingMutations[] = [$mutation, $isThisTheOnlyNonBreakingIfElseBranch];
                                    }
                                }
                            }

                        } else {
                            if (isset($mutation->changes['type'])) {
                                $baseType = $mutation->changes['type'];
                                break 2;
                            }

                            $affectingMutations[] = [$mutation, true];
                        }
                    }
                }
            }

            $currentLine--;
        }

        $resolvedType = $baseType?->unwrapType($this->scope->config);

        for ($mutationIndex = count($affectingMutations) - 1; $mutationIndex >= 0; $mutationIndex--) {
            [$mutation, $isCertain] = $affectingMutations[$mutationIndex];

            if (isset($mutation->changes['type'])) {
                if ($isCertain) {
                    $resolvedType = $mutation->changes['type']->unwrapType($this->scope->config);
                    break;
                }

                $resolvedType = (new UnionType(array_values(array_filter([$resolvedType, $mutation->changes['type']]))))->unwrapType($this->scope->config);
            }

            if (! empty($mutation->changes['attributes'])) {
                $this->scope->withShapeMerging(function () use ($mutation, &$resolvedType, $isCertain) {
                    foreach ($mutation->changes['attributes'] as $key => $attributeType) {
                        $resolvedType = $this->mergeAttribute($resolvedType, $key, $attributeType, $isCertain);
                    }
                });
            }
        }

        if (! $resolvedType) {
            $resolvedType = new UnknownType;
        }

        $resolvedType->addDescription($this->description);
        $resolvedType->examples = $this->examples ?: $resolvedType->examples;
        $resolvedType->required = $this->required ?: $resolvedType->required;

        $this->resolvedType = $resolvedType;

        return $resolvedType;
    }


    private function mergeAttribute(?Type $baseType, int|string $key, Type $attributeType, bool $isCertain): Type
    {
        if ($isCertain) {
            $attributeType = $this->setNestedAttributeAsRequired($attributeType);
        }

        $potentialTypes = $baseType instanceof UnionType ? $baseType->types : array_filter([$baseType]);
        $typesWithAddedAttribute = [];

        for ($i = 0; $i < count($potentialTypes); $i++) {
            if ($potentialTypes[$i] instanceof ObjectType) {
                $keyString = (string) $key;

                if (isset($potentialTypes[$i]->properties[$keyString])) {
                    if ($isCertain && ! ($attributeType instanceof ArrayType || $attributeType instanceof ObjectType)) {
                        $potentialTypes[$i]->properties[$keyString] = $attributeType;

                    } else {
                        $potentialTypes[$i]->properties[$keyString] = (new UnionType([
                            $potentialTypes[$i]->properties[$keyString],
                            $attributeType,
                        ]))->unwrapType($this->scope->config)->unwrapType($this->scope->config);
                    }

                } else {
                    $potentialTypes[$i]->properties[$keyString] = $attributeType->setRequired($isCertain);
                }

                $typesWithAddedAttribute[] = $potentialTypes[$i];

            } else if ($potentialTypes[$i] instanceof ArrayType) {
                if (isset($potentialTypes[$i]->shape[$key])) {
                    if ($isCertain && ! ($attributeType instanceof ArrayType || $attributeType instanceof ObjectType)) {
                        $potentialTypes[$i]->shape[$key] = $attributeType;

                    } else {
                        $potentialTypes[$i]->shape[$key] = (new UnionType([
                            $potentialTypes[$i]->shape[$key],
                            $attributeType,
                        ]))->unwrapType($this->scope->config)->unwrapType($this->scope->config);
                    }

                } else {
                    $potentialTypes[$i]->addItemToArray($key, $attributeType->setRequired($isCertain));
                }

                $typesWithAddedAttribute[] = $potentialTypes[$i];
            }
        }

        if ($isCertain) {
            if (empty($typesWithAddedAttribute)) {
                $baseType = new ArrayType;
                $baseType->addItemToArray($key, $attributeType->setRequired(true));

            } else {
                $baseType = (new UnionType($typesWithAddedAttribute))->unwrapType($this->scope->config);
            }

        } else {
            if (empty($typesWithAddedAttribute)) {
                $arrayType = new ArrayType;
                $arrayType->addItemToArray($key, $attributeType);

                $baseType = (new UnionType([...$potentialTypes, $arrayType]))->unwrapType($this->scope->config);

            } else {
                $baseType = (new UnionType($potentialTypes))->unwrapType($this->scope->config);
            }
        }

        return $baseType;
    }


    private function setNestedAttributeAsRequired(Type $type): Type
    {
        if ($type instanceof ArrayType) {
            $type->shape = array_map($this->setNestedAttributeAsRequired(...), $type->shape);
            $type->itemType?->setRequired(true);
        }

        return $type->setRequired(true);
    }
}
