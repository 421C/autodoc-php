<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\ClassWithDocumentedNullableProperty;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Property types are cached on the PhpClass and shared by every operation
 * that renders the class, so rendering a schema must not mutate the Type —
 * a render that appends a union's description onto its member duplicates
 * the description on every subsequent render.
 */
final class SchemaRenderIdempotenceTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function nullablePropertyDescriptionIsNotDuplicatedByRepeatedRenders(): void
    {
        $config = self::loadConfig();
        $scope = new Scope($config);

        $type = $scope->getPhpClass(ClassWithDocumentedNullableProperty::class)->resolveType();

        $expectedSchema = [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => [
                        'string',
                        'null',
                    ],
                    'description' => 'Creation date (UTC).',
                ],
            ],
            'required' => [
                'date',
            ],
        ];

        foreach (range(1, 3) as $render) {
            $this->assertSchemaArraysMatch($expectedSchema, $type->toSchema($config), 'render #' . $render, 'schema');
        }
    }
}
