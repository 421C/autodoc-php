<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\TestProject\Entities\ImportedSymbolsHolder;
use AutoDoc\Tests\Traits\ComparesSchemaArrays;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

use function AutoDoc\Tests\TestProject\Support\makeStatus;
use const AutoDoc\Tests\TestProject\Support\DEFAULT_STATUS;

require_once __DIR__ . '/../TestProject/Support/functions.php';

/**
 * Unqualified calls and constant fetches resolve through the file's
 * `use function` and `use const` imports. Class method bodies are analyzed
 * without php-parser's NameResolver, so the analyzer resolves these names
 * from its own alias table.
 */
final class FunctionAndConstImportTest extends TestCase
{
    use ComparesSchemaArrays, LoadsConfig;

    #[Test]
    public function methodResolvesImportedAndAliasedFunctionsAndConstants(): void
    {
        $schema = $this->getMethodReturnSchema('usesDirectImports');

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'const' => 'ok',
                ],
                'count' => [
                    'type' => 'integer',
                    'const' => 42,
                ],
                'defaultStatus' => [
                    'type' => 'string',
                    'const' => 'pending',
                ],
                'maxAttempts' => [
                    'type' => 'integer',
                    'const' => 3,
                ],
            ],
            'required' => [
                'status',
                'count',
                'defaultStatus',
                'maxAttempts',
            ],
        ], $schema, 'usesDirectImports', 'return');
    }

    #[Test]
    public function methodResolvesGroupUseFunctionAndConstantImports(): void
    {
        $schema = $this->getMethodReturnSchema('usesGroupImports');

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'count' => [
                    'type' => 'integer',
                    'const' => 42,
                ],
                'maxAttempts' => [
                    'type' => 'integer',
                    'const' => 3,
                ],
            ],
            'required' => [
                'count',
                'maxAttempts',
            ],
        ], $schema, 'usesGroupImports', 'return');
    }

    #[Test]
    public function closureResolvesImportedFunctionsAndConstants(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            return [
                'status' => makeStatus(),
                'defaultStatus' => DEFAULT_STATUS,
            ];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'const' => 'ok',
                ],
                'defaultStatus' => [
                    'type' => 'string',
                    'const' => 'pending',
                ],
            ],
            'required' => [
                'status',
                'defaultStatus',
            ],
        ], $schema, 'closure', 'return');
    }

    #[Test]
    public function closureResolvesUnqualifiedNamesFromItsOwnNamespace(): void
    {
        $schema = $this->getClosureReturnSchema(function (): mixed {
            return [
                'helper' => namespaceLocalHelper(),
                'constant' => NAMESPACE_LOCAL_CONSTANT,
            ];
        });

        $this->assertSchemaArraysMatch([
            'type' => 'object',
            'properties' => [
                'helper' => [
                    'type' => 'integer',
                    'const' => 7,
                ],
                'constant' => [
                    'type' => 'string',
                    'const' => 'ns',
                ],
            ],
            'required' => [
                'helper',
                'constant',
            ],
        ], $schema, 'closure', 'return');
    }

    /**
     * @return array<string, mixed>
     */
    private function getMethodReturnSchema(string $methodName): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $scope = new Scope($config);

        $type = $scope->getPhpClass(ImportedSymbolsHolder::class)
            ->getMethod($methodName)
            ->getReturnType(usePhpDocIfAvailable: false);

        return $type->toSchema($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function getClosureReturnSchema(Closure $closure): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = true;

        $scope = new Scope($config);

        $type = new PhpCallable(
            scope: $scope,
            reflection: new ReflectionFunction($closure),
        )->getReturnType(usePhpDocIfAvailable: false);

        return $type->toSchema($config);
    }
}

const NAMESPACE_LOCAL_CONSTANT = 'ns';

function namespaceLocalHelper(): int
{
    return 7;
}
