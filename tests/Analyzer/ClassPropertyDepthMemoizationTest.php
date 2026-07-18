<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\Tests\TestProject\Entities\PropertyDepthHolder;
use AutoDoc\Tests\TestProject\Entities\VirtualPropertyDepthHolder;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClassPropertyDepthMemoizationTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function largerDepthBudgetReplacesTruncatedPropertyCache(): void
    {
        $config = self::loadConfig();
        $config->data['max_depth'] = 3;

        $deepScope = new Scope($config, depth: 3);
        $deepClass = $deepScope->getPhpClass(PropertyDepthHolder::class);
        $truncatedChild = $deepClass->getProperty('child')?->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $truncatedChild);
        self::assertSame([], $truncatedChild->properties);

        $freshScope = new Scope($config);
        $freshClass = $freshScope->getPhpClass(PropertyDepthHolder::class);
        $fullChild = $freshClass->getProperty('child')?->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $fullChild);
        self::assertArrayHasKey('n', $fullChild->properties);

        $reusedDeepScope = new Scope($config, depth: 3);
        $reusedDeepClass = $reusedDeepScope->getPhpClass(PropertyDepthHolder::class);
        $reusedFullChild = $reusedDeepClass->getProperty('child')?->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $reusedFullChild);
        self::assertArrayHasKey('n', $reusedFullChild->properties);
    }


    #[Test]
    public function truncatedVirtualPropertyResolutionDoesNotLimitLaterResolution(): void
    {
        $config = self::loadConfig();
        $config->data['max_depth'] = 3;

        $deepScope = new Scope($config, depth: 3);
        $deepClass = $deepScope->getPhpClass(VirtualPropertyDepthHolder::class);
        $truncatedChild = $deepClass->getProperty('child')?->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $truncatedChild);
        self::assertSame([], $truncatedChild->properties);

        $freshScope = new Scope($config);
        $freshClass = $freshScope->getPhpClass(VirtualPropertyDepthHolder::class);
        $fullChild = $freshClass->getProperty('child')?->unwrapType($config);

        self::assertInstanceOf(ObjectType::class, $fullChild);
        self::assertArrayHasKey('n', $fullChild->properties);
    }
}
