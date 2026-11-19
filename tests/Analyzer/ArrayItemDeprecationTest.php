<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class ArrayItemDeprecationTest extends TestCase
{
    use LoadsConfig;

    #[Test]
    public function aDeprecatedItemWithALiteralValueKeepsTheDeprecation(): void
    {
        $this->assertSame(
            [
                'type' => 'string',
                'description' => 'Old name.',
                'deprecated' => true,
                'x-deprecated-description' => 'Use name.',
                'const' => 'x',
            ],
            $this->getItemSchema(fn () => [
                /**
                 * Old name.
                 *
                 * @deprecated Use name.
                 */
                'title' => 'x',
            ]),
        );
    }


    #[Test]
    public function aDeprecatedItemWithAVarTagKeepsTheDeprecation(): void
    {
        $this->assertSame(
            [
                'type' => 'string',
                'deprecated' => true,
                'x-deprecated-description' => 'Use name.',
            ],
            $this->getItemSchema(fn () => [
                /**
                 * @var string
                 * @deprecated Use name.
                 */
                'title' => rand(0, 1) ? 'a' : 'b',
            ]),
        );
    }


    #[Test]
    public function aDeprecatedItemWithoutAMessageKeepsTheFlag(): void
    {
        $this->assertSame(
            [
                'type' => 'string',
                'deprecated' => true,
            ],
            $this->getItemSchema(fn (string $value) => [
                /** @deprecated */
                'title' => $value,
            ]),
        );
    }


    /**
     * @return array<string, mixed>
     */
    private function getItemSchema(Closure $closure): array
    {
        $config = self::loadConfig();

        $schema = new PhpCallable(
            scope: new Scope($config),
            reflection: new ReflectionFunction($closure),
        )->getReturnType()->toSchema($config);

        $this->assertIsArray($schema['properties'] ?? null);
        $this->assertIsArray($schema['properties']['title'] ?? null);

        return $schema['properties']['title'];
    }
}
