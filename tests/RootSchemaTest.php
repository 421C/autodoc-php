<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Config;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DocGenerator;
use AutoDoc\Tests\Traits\LoadsConfig;
use AutoDoc\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RootSchemaTest extends TestCase
{
    use LoadsConfig;

    protected function setUp(): void
    {
        Workspace::$schemaComponents = [];
    }

    protected function tearDown(): void
    {
        Workspace::$schemaComponents = [];
    }


    #[Test]
    public function aDocumentWithoutRoutesOrComponentsHasEmptyPathsAndNoComponents(): void
    {
        $openApiJson = $this->generate(self::loadConfig());

        $this->assertStringContainsString('"paths":{}', $openApiJson);
        $this->assertStringNotContainsString('"components"', $openApiJson);
    }


    #[Test]
    public function securitySchemesWithoutSchemaComponentsOmitSchemas(): void
    {
        $config = self::loadConfig();
        $config->data['openapi']['security_schemes'] = [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
            ],
        ];

        $this->assertStringContainsString(
            '"components":{"securitySchemes":{"bearerAuth":{"type":"http","scheme":"bearer"}}}',
            $this->generate($config),
        );
    }


    #[Test]
    public function schemaComponentsAreKeptWhenPresent(): void
    {
        Workspace::$schemaComponents['Status'] = new StringType(['draft', 'published']);

        $this->assertStringContainsString(
            '"components":{"schemas":{"Status":{"type":"string","enum":["draft","published"]}}}',
            $this->generate(self::loadConfig()),
        );
    }


    private function generate(Config $config): string
    {
        $config->data['workspaces'] = [
            'empty' => [
                'routes' => [],
            ],
        ];

        $config->selectedWorkspaceKey = 'empty';
        $config->selectedWorkspace = $config->getWorkspace('empty');

        return json_encode((new DocGenerator)->makeOpenApi($config), JSON_THROW_ON_ERROR);
    }
}
