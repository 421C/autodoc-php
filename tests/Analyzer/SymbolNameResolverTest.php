<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Ast\SymbolNameResolver;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;


final class SymbolNameResolverTest extends TestCase
{
    #[Test]
    public function classNameResolutionIgnoresFunctionAndConstantImports(): void
    {
        $resolver = $this->createResolver();

        self::assertSame('Vendor\ImportedClass', $resolver->getResolvedClassName('Imported'));
        self::assertSame('Package\GroupedClass', $resolver->getResolvedClassName('Grouped'));
        self::assertSame('App\FunctionAlias', $resolver->getResolvedClassName('FunctionAlias'));
        self::assertSame('App\ConstantAlias', $resolver->getResolvedClassName('ConstantAlias'));
        self::assertSame('App\GroupedFunction', $resolver->getResolvedClassName('GroupedFunction'));
        self::assertSame('App\GroupedConstant', $resolver->getResolvedClassName('GroupedConstant'));
    }

    #[Test]
    public function functionNamesResolveThroughFunctionImports(): void
    {
        $resolver = $this->createResolver();

        self::assertSame('Vendor\function_name', $resolver->getResolvedFunctionName('FunctionAlias'));
        self::assertSame('Package\grouped_function', $resolver->getResolvedFunctionName('GroupedFunction'));
        self::assertSame('strlen', $resolver->getResolvedFunctionName('strlen'));
        self::assertSame('Vendor\ImportedClass\helper', $resolver->getResolvedFunctionName('Imported\helper'));
    }

    #[Test]
    public function constantNamesResolveThroughConstantImports(): void
    {
        $resolver = $this->createResolver();

        self::assertSame('Vendor\CONSTANT_NAME', $resolver->getResolvedConstantName('ConstantAlias'));
        self::assertSame('Package\GROUPED_CONSTANT', $resolver->getResolvedConstantName('GroupedConstant'));
        self::assertSame('PHP_INT_MAX', $resolver->getResolvedConstantName('PHP_INT_MAX'));
        self::assertSame('Vendor\ImportedClass\SOME_CONSTANT', $resolver->getResolvedConstantName('Imported\SOME_CONSTANT'));
    }

    private function createResolver(): SymbolNameResolver
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse(<<<'PHP'
            <?php

            namespace App;

            use Vendor\ImportedClass as Imported;
            use function Vendor\function_name as FunctionAlias;
            use const Vendor\CONSTANT_NAME as ConstantAlias;
            use Package\{
                GroupedClass as Grouped,
                function grouped_function as GroupedFunction,
                const GROUPED_CONSTANT as GroupedConstant,
            };
            PHP);

        self::assertNotNull($ast);

        $resolver = new SymbolNameResolver;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($resolver);
        $traverser->traverse($ast);

        return $resolver;
    }
}
