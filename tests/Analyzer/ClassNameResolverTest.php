<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\Ast\ClassNameResolver;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;


final class ClassNameResolverTest extends TestCase
{
    #[Test]
    public function itIgnoresFunctionAndConstantImports(): void
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

        $resolver = new ClassNameResolver;
        $traverser = new NodeTraverser;
        $traverser->addVisitor($resolver);
        $traverser->traverse($ast);

        self::assertSame('Vendor\ImportedClass', $resolver->getResolvedClassName('Imported'));
        self::assertSame('Package\GroupedClass', $resolver->getResolvedClassName('Grouped'));
        self::assertSame('App\FunctionAlias', $resolver->getResolvedClassName('FunctionAlias'));
        self::assertSame('App\ConstantAlias', $resolver->getResolvedClassName('ConstantAlias'));
        self::assertSame('App\GroupedFunction', $resolver->getResolvedClassName('GroupedFunction'));
        self::assertSame('App\GroupedConstant', $resolver->getResolvedClassName('GroupedConstant'));
    }
}
