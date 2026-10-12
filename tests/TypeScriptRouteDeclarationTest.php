<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\RouteMutationTrackingLoader;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\TypeScript\TypeScriptFile;
use AutoDoc\TypeScript\TypeScriptGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TypeScriptRouteDeclarationTest extends TestCase
{
    use LoadsConfig;

    protected function tearDown(): void
    {
        RouteMutationTrackingLoader::$route = null;
    }

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
    public function binaryStringsAreFilesInRequestsAndBlobsInResponses(): void
    {
        $route = new Route(
            uri: '/api/test/upload',
            method: 'post',
            closure: function (): void {},
        );

        $route->addRequestBodyType(new ObjectType(properties: [
            'avatar' => (new StringType(format: 'binary'))->setRequired(true),
        ]));

        $route->addResponse(
            status: 200,
            contentType: '*/*',
            body: new ObjectType(typeToDisplay: new StringType(format: 'binary')),
        );

        RouteMutationTrackingLoader::$route = $route;

        $config = self::loadConfig();
        $config->data['route_loader'] = RouteMutationTrackingLoader::class;

        $file = new TypeScriptFile(null, new TypeScriptGenerator);
        $file->lines = explode("\n", <<<'TS'
        /** @autodoc POST /api/test/upload request */
        /** @autodoc POST /api/test/upload */
        TS);

        $file->processAutodocTags(new Scope($config));

        $this->assertSame(<<<'TS'
        /** @autodoc POST /api/test/upload request */
        type UploadRequest = {
            avatar: File
        }
        /** @autodoc POST /api/test/upload */
        type UploadResponse = Blob
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
