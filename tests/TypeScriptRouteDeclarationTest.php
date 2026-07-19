<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeScriptFile;
use AutoDoc\TypeScript\TypeScriptGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeScriptRouteDeclarationTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function itGeneratesRequestAndResponseDeclarationsThroughRouteTags(): void
    {
        $file = new TypeScriptFile(null, new TypeScriptGenerator);
        $file->lines = explode("\n", <<<'TS'
        /** @autodoc POST /api/test/xmlrequest/process */
        /** @autodoc POST /api/test/xmlrequest/process request */
        TS);

        $file->processAutodocTags(new Scope(self::loadConfig()));

        $this->assertSame(<<<'TS'
        /** @autodoc POST /api/test/xmlrequest/process */
        type ProcessResponse = {
            error: 'Invalid XML'
        }|Array<{
            customer: unknown
            amount: number
            points: 10|1
        }>
        /** @autodoc POST /api/test/xmlrequest/process request */
        type ProcessRequest = string
        TS, implode("\n", $file->lines));
    }

    #[Test]
    public function itReportsMissingRoutesWithTheTagLocation(): void
    {
        $file = new TypeScriptFile(null, new TypeScriptGenerator);
        $file->filePath = 'client.ts';
        $file->lines = [
            '/** @autodoc GET /missing */',
        ];

        $this->expectExceptionMessage('Route "GET /missing" not found [client.ts:1]');

        $file->processAutodocTags(new Scope(self::loadConfig()));
    }
}
