<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer\DocBlock;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\ConstFetchDocTypesHolder;
use AutoDoc\Tests\TestProject\Entities\StateEnum;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TestProject/Support/functions.php';

final class ConstFetchDocTypeTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function docBlockConstantFetchesResolveToTheirValues(): void
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $scope = new Scope($config);

        $schema = $scope->getPhpClass(ConstFetchDocTypesHolder::class)
            ->getMethod('docTypes')
            ->getReturnType()
            ->toSchema($config);

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'classConstant' => [
                    'type' => 'string',
                    'const' => 'active',
                ],
                'qualifiedConstant' => [
                    'type' => 'integer',
                    'const' => 1,
                ],
                'wildcard' => [
                    'type' => 'string',
                    'enum' => ['active', 'archived'],
                ],
                'importedConstant' => [
                    'type' => 'string',
                    'const' => 'pending',
                ],
                'globalConstant' => [
                    'type' => 'integer',
                    'const' => PHP_INT_SIZE,
                ],
                'enumCase' => [
                    'type' => 'integer',
                    'enum' => [1, 2],
                    'description' => '[StateEnum](#/schemas/StateEnum)',
                ],
                'enumWildcard' => [
                    'anyOf' => [
                        [
                            'type' => 'integer',
                            'enum' => [1, 2],
                            'description' => '[StateEnum](#/schemas/StateEnum)',
                        ],
                        [
                            'type' => 'integer',
                            'const' => 1,
                        ],
                    ],
                ],
                'className' => [
                    'type' => 'string',
                    'const' => StateEnum::class,
                ],
            ],
            'required' => [
                'classConstant',
                'qualifiedConstant',
                'wildcard',
                'importedConstant',
                'globalConstant',
                'enumCase',
                'enumWildcard',
                'className',
            ],
        ], $schema, 'docTypes', 'return');
    }
}
