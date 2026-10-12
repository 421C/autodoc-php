<?php declare(strict_types=1);

namespace AutoDoc\Tests;

use AutoDoc\Config;
use AutoDoc\DocGenerator;
use AutoDoc\OpenApi\Operation;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\Extensions\AppendApiKeySecurityExtension;
use AutoDoc\Tests\TestProject\RouteLoader;
use AutoDoc\Tests\Traits\LoadsConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function workspaceSecuritySchemesReplaceTopLevelSchemesByName(): void
    {
        $config = self::loadConfig();

        $topLevelSchemes = [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Personal access token',
            ],
            'apiKeyAuth' => [
                'type' => 'apiKey',
                'in' => 'header',
                'name' => 'X-API-Key',
            ],
        ];

        $config->data['openapi']['security_schemes'] = $topLevelSchemes;

        $config->data['workspaces'] = [
            'public' => [
                'routes' => [],
            ],
            'partner' => [
                'routes' => [],
                'security_schemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
        ];

        $this->assertSame(
            $topLevelSchemes,
            $this->generateComponents($config, 'public')['securitySchemes'] ?? null,
        );

        $this->assertSame(
            [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
                'apiKeyAuth' => $topLevelSchemes['apiKeyAuth'],
            ],
            $this->generateComponents($config, 'partner')['securitySchemes'] ?? null,
        );
    }


    #[Test]
    public function securitySchemesAreOmittedWhenNoneAreConfigured(): void
    {
        $config = self::loadConfig();

        $config->data['workspaces'] = [
            'public' => [
                'routes' => [],
            ],
        ];

        $this->assertArrayNotHasKey('securitySchemes', $this->generateComponents($config, 'public'));
    }


    #[Test]
    public function securityRequirementsAreObjectsAndNullSecurityIsOmitted(): void
    {
        $this->assertSame(
            '{"summary":"List","security":[{},{"bearerAuth":["museum-objects.view"]}]}',
            json_encode(new Operation(summary: 'List', security: [[], ['bearerAuth' => ['museum-objects.view']]])),
        );

        $this->assertSame(
            '{"summary":"List","security":[]}',
            json_encode(new Operation(summary: 'List', security: [])),
        );

        $this->assertSame(
            '{"summary":"List"}',
            json_encode(new Operation(summary: 'List')),
        );
    }


    #[Test]
    public function routeSecurityIsCopiedBeforeOperationExtensionsRun(): void
    {
        $config = self::loadConfig();
        $config->data['extensions'] = [AppendApiKeySecurityExtension::class];

        $route = new Route(
            uri: '/api/test/security',
            method: 'get',
            closure: function () {},
        );

        $route->addSecurityRequirement(['bearerAuth' => ['museum-objects.view']]);

        $operation = new RouteLoader($config)->routeToOperation($route);

        $this->assertSame(
            [
                ['bearerAuth' => ['museum-objects.view']],
                ['apiKeyAuth' => []],
            ],
            $operation?->security,
        );
    }


    /**
     * @return array<string, mixed>
     */
    private function generateComponents(Config $config, string $workspaceKey): array
    {
        $config->selectedWorkspaceKey = $workspaceKey;
        $config->selectedWorkspace = $config->getWorkspace($workspaceKey);

        $openApiJson = json_encode((new DocGenerator)->makeOpenApi($config), JSON_THROW_ON_ERROR);

        /** @var array{components: array<string, mixed>} */
        $openApi = json_decode($openApiJson, true, flags: JSON_THROW_ON_ERROR);

        return $openApi['components'];
    }
}
