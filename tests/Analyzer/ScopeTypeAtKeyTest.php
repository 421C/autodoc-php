<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\Tests\TestProject\Entities\SimpleClass;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScopeTypeAtKeyTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function materializedPropertyIsReturned(): void
    {
        $scope = new Scope(self::loadConfig());

        $objectType = new ObjectType(properties: ['name' => new StringType]);

        self::assertInstanceOf(StringType::class, $scope->getTypeAtKey($objectType, 'name'));
    }

    #[Test]
    public function unmaterializedPropertyFallsBackToClassMetadata(): void
    {
        $config = self::loadConfig();
        $scope = new Scope($config);

        $objectType = new ObjectType(className: SimpleClass::class);

        $propertyType = $scope->getTypeAtKey($objectType, 'n');

        self::assertNotInstanceOf(UnknownType::class, $propertyType);
        self::assertSame(['type' => ['integer', 'null']], $propertyType->toSchema($config));
    }

    #[Test]
    public function missingClassPropertyResolvesAsUnknown(): void
    {
        $scope = new Scope(self::loadConfig());

        $objectType = new ObjectType(className: SimpleClass::class);

        self::assertInstanceOf(UnknownType::class, $scope->getTypeAtKey($objectType, 'undeclared'));
    }

    #[Test]
    public function arrayShapeEntryIsReturned(): void
    {
        $scope = new Scope(self::loadConfig());

        $arrayType = new ArrayType(shape: ['name' => new StringType]);

        self::assertInstanceOf(StringType::class, $scope->getTypeAtKey($arrayType, 'name'));
    }
}
