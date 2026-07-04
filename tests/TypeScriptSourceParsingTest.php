<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\StringType;
use AutoDoc\Tests\TestProject\Entities\ClassWithTypeAliasDefinition;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeScriptFile;
use AutoDoc\TypeScript\TypeScriptGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeScriptSourceParsingTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function itFindsMultilineTagsAndExistingDeclarationBoundaries(): void
    {
        $file = new TypeScriptFile(null, new TypeScriptGenerator);
        $file->lines = explode("\n", <<<'TS'
        /**
         * @autodoc array{
         *     value: string
         * }
         */
        type Existing = {
            literal: '}'
            /* ignored: { */
            nested: {
                value: string
            }
        }
        const untouched = true
        TS);

        $tag = $file->findFirstAutodocTag(new Scope(self::loadConfig()));

        $this->assertNotNull($tag);
        $this->assertSame(4, $tag->lineIndex);
        $this->assertSame("array{\n *     value: string\n * }\n ", $tag->value);
        $this->assertSame('Existing', $tag->getExistingStructureName());
        $this->assertCount(7, $tag->getExistingStructureLines());
    }

    #[Test]
    public function itParsesOptionsAndUsesTheFromClassScope(): void
    {
        $file = new TypeScriptFile(null, new TypeScriptGenerator);
        $file->lines = [
            '/** @autodoc TestTypeAlias {'
                . " from: AutoDoc\\Tests\\TestProject\\Entities\\ClassWithTypeAliasDefinition,"
                . " only: 'a'|'b',"
                . ' with: array{ label: string },'
                . " mode: 'separate_file',"
                . " as: 'Alias'"
                . ' } */',
        ];

        $tag = $file->findFirstAutodocTag(new Scope(self::loadConfig()));

        $this->assertNotNull($tag);
        $this->assertSame('TestTypeAlias', $tag->value);
        $this->assertSame(ClassWithTypeAliasDefinition::class, $tag->scope->className);
        if (! isset($tag->options['only'], $tag->options['mode'], $tag->options['as'], $tag->options['with'])) {
            $this->fail('Expected all parsed tag options to be present.');
        }

        $this->assertSame(['a', 'b'], $tag->options['only']);
        $this->assertSame('separate_file', $tag->options['mode']);
        $this->assertSame('Alias', $tag->options['as']);
        $this->assertInstanceOf(StringType::class, $tag->options['with']['label']);
    }

    #[Test]
    public function itAddsSourceLocationToTagParserErrors(): void
    {
        $file = new TypeScriptFile(null, new TypeScriptGenerator);
        $file->filePath = 'example.ts';
        $file->lines = [
            '/** @autodoc string { as: 123 } */',
        ];

        $this->expectExceptionMessage('The value of `as` tag must be a string. [example.ts:1]');

        $file->findFirstAutodocTag(new Scope(self::loadConfig()));
    }
}
