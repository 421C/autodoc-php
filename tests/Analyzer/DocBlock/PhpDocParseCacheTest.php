<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer\DocBlock;

use AutoDoc\Analyzer\DocBlock\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PhpDocParseCacheTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function parsedNodesAreReusedForOneConfigLifetime(): void
    {
        $docComment = <<<'PHPDOC'
            /**
             * @method FirstResult first()
             * @method SecondResult second()
             * @method ThirdResult third()
             */
            PHPDOC;
        $config = self::loadConfig();
        $first = new PhpDoc($docComment, new Scope(config: $config));
        $second = new PhpDoc($docComment, new Scope(config: $config));
        $otherConfig = new PhpDoc($docComment, new Scope(config: self::loadConfig()));
        $node = new ReflectionProperty(PhpDoc::class, 'node');

        self::assertSame($node->getValue($first), $node->getValue($second));
        self::assertNotSame($node->getValue($first), $node->getValue($otherConfig));
    }
}
