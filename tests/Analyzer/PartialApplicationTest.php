<?php declare(strict_types=1);

namespace AutoDoc\Tests\Analyzer;

use AutoDoc\Analyzer\PhpCallable;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\CallableType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Route;
use AutoDoc\Tests\TestProject\Entities\GenericClass;
use AutoDoc\Tests\TestProject\Entities\TextProcessor;
use AutoDoc\Tests\TestProject\Extensions\SideEffectMethodExtension;
use AutoDoc\Tests\Traits\LoadsConfig;
use Closure;
use Override;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * First-class callable syntax (`foo(...)`, PHP 8.1) is written as real closures.
 * Partial application (`foo(?)`, PHP 8.6) cannot be loaded by the running PHP, so
 * those cases are parsed from source and analyzed as AST.
 */
final class PartialApplicationTest extends TestCase
{
    use LoadsConfig;

    protected function setUp(): void
    {
        SideEffectMethodExtension::$dispatchLog = [];
    }

    #[Test]
    public function firstClassCallableOverFunctionIsNotInvoked(): void
    {
        $type = $this->analyzeClosure(fn () => strlen(...));

        $this->assertInstanceOf(CallableType::class, $type);
    }

    #[Test]
    public function firstClassCallableOverFunctionResolvesReturnTypeWhenInvoked(): void
    {
        $type = $this->analyzeClosure(function () {
            $length = strlen(...);

            return ['value' => $length('abc')];
        });

        $this->assertSame(['value' => ['type' => 'integer']], $this->properties($type));
    }

    #[Test]
    public function arrayMapResolvesItemTypeFromFirstClassCallable(): void
    {
        $type = $this->analyzeClosure(fn () => ['values' => array_map(strlen(...), ['a', 'bb'])]);

        $this->assertSame(
            ['values' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            $this->properties($type),
        );
    }

    #[Test]
    public function firstClassCallableOverInstanceMethodResolvesReturnTypeWhenInvoked(): void
    {
        $type = $this->analyzeClosure(function () {
            $process = new TextProcessor()->process(...);

            return $process('hello');
        });

        $this->assertSame(['text' => ['type' => 'string']], $this->properties($type));
    }

    #[Test]
    public function firstClassCallableOverStaticMethodResolvesReturnTypeWhenInvoked(): void
    {
        $type = $this->analyzeClosure(function () {
            $wrap = GenericClass::from(...);

            return ['data' => $wrap(145)->data];
        });

        $this->assertSame(['data' => ['type' => 'integer']], $this->properties($type));
    }

    #[Test]
    public function partialApplicationResolvesToCallableInsteadOfCalleeReturnType(): void
    {
        $type = $this->analyzeSource('return str_contains(\'haystack\', ?);');

        $this->assertInstanceOf(CallableType::class, $type);
    }

    #[Test]
    public function namedPlaceholderResolvesToCallable(): void
    {
        $type = $this->analyzeSource('return str_contains(needle: ?, haystack: \'haystack\');');

        $this->assertInstanceOf(CallableType::class, $type);
    }

    #[Test]
    public function boundVariadicPlaceholderResolvesToCallable(): void
    {
        $type = $this->analyzeSource('return str_contains(\'haystack\', ...);');

        $this->assertInstanceOf(CallableType::class, $type);
    }

    #[Test]
    public function partialApplicationDoesNotRunSideEffectExtensions(): void
    {
        $this->analyzeSource('$processor = new \AutoDoc\Tests\TestProject\Entities\TextProcessor; return $processor->process(?);');

        $this->assertNotContains('process', SideEffectMethodExtension::$dispatchLog);
    }

    #[Test]
    public function ordinaryCallStillRunsSideEffectExtensions(): void
    {
        $this->analyzeSource('$processor = new \AutoDoc\Tests\TestProject\Entities\TextProcessor; return $processor->process(\'x\');');

        $this->assertContains('process', SideEffectMethodExtension::$dispatchLog);
    }

    private function analyzeClosure(Closure $closure): ?Type
    {
        $scope = $this->createScope();

        return new PhpCallable(scope: $scope, reflection: new ReflectionFunction($closure))
            ->analyzeBody(analyzeReturnValue: true, isOperationEntrypoint: true)['analyzedReturnType'];
    }

    private function analyzeSource(string $body): ?Type
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse(
            "<?php \$probe = function () {\n" . $body . "\n};",
        );

        $this->assertNotNull($ast);

        $nameResolverTraverser = new NodeTraverser;
        $nameResolverTraverser->addVisitor(new NameResolver);

        $finder = new class extends NodeVisitorAbstract
        {
            public ?Node\Expr\Closure $closureNode = null;

            #[Override]
            public function enterNode(Node $node): null
            {
                if ($this->closureNode === null && $node instanceof Node\Expr\Closure) {
                    $this->closureNode = $node;
                }

                return null;
            }
        };

        $finderTraverser = new NodeTraverser;
        $finderTraverser->addVisitor($finder);
        $finderTraverser->traverse($nameResolverTraverser->traverse($ast));

        $this->assertNotNull($finder->closureNode);

        return new PhpCallable(scope: $this->createScope(), node: $finder->closureNode)
            ->analyzeBody(analyzeReturnValue: true, isOperationEntrypoint: true)['analyzedReturnType'];
    }

    private function createScope(): Scope
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = false;
        $config->data['extensions'] = [SideEffectMethodExtension::class];

        return new Scope(config: $config, route: new Route(uri: '/test', method: 'post'));
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(?Type $type): array
    {
        $config = self::loadConfig();
        $config->data['openapi']['show_values_for_scalar_types'] = false;

        $schema = $type?->toSchema($config) ?? [];

        return is_array($schema['properties'] ?? null) ? $schema['properties'] : $schema;
    }
}
