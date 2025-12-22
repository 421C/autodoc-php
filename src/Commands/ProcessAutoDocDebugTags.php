<?php declare(strict_types=1);

namespace AutoDoc\Commands;

use AutoDoc\Analyzer\ClassMethodNodeVisitor;
use AutoDoc\Analyzer\NameResolver;
use AutoDoc\Analyzer\Scope;
use AutoDoc\Config;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnresolvedParserNodeType;
use Exception;
use Override;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Process @autodoc debug tags in PHP code
 */
class ProcessAutoDocDebugTags
{
    public function __construct(
        protected Config $config,
        protected ?int $resolutionDepth = null,
    ) {}


    public function __invoke(string $path): void
    {
        if (! file_exists($path)) {
            $this->error("Invalid path: $path");

            return;
        }

        if (isset($this->config->data['memory_limit'])) {
            ini_set('memory_limit', $this->config->data['memory_limit']);
        }

        if (is_dir($path)) {
            $this->processDirectory($path);

        } else {
            $filePath = realpath($path);

            if (! $filePath) {
                $this->error("Invalid path: $filePath");

                return;
            }

            $this->processPhpFile($filePath, $path);
        }
    }


    protected function processDirectory(string $workingDirectory): void
    {
        /** @var iterable<\SplFileInfo> */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workingDirectory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getRealPath();

                if ($filePath) {
                    $this->processPhpFile($filePath, $workingDirectory);
                }
            }
        }
    }

    protected function processPhpFile(string $filePath, string $workingDirectory): void
    {
        $code = file_get_contents($filePath);

        if (! $code || !str_contains($code, '@autodoc')) {
            return;
        }

        $lines = explode("\n", $code);

        foreach ($lines as $index => $line) {
            if (preg_match('/^[\s\*\/]*@autodoc\s+debug\s+(\S+)\s*[\s\*\/]*$/', $line, $matches)) {
                $debugTarget = $matches[1];

                $parser = (new ParserFactory)->createForNewestSupportedVersion();
                $ast = $parser->parse($code);

                if (! $ast) {
                    throw new Exception('Error parsing file "' . $this->formatFilePath($filePath, $workingDirectory) . '".');
                }

                $tagLocation = $this->formatFilePath($filePath, $workingDirectory) . ':' . ($index + 1);

                [$className, $methodName, $autodocCommentNode] = $this->getTargetClassMethod($ast, line: $index + 1);

                if (!$className || !$methodName) {
                    $this->error("Skipping @autodoc tag because it is not inside a class method [$tagLocation]");
                    $newLines[] = $line;

                    continue;
                }

                if (! $autodocCommentNode) {
                    $this->error("Failed to parse @autodoc debug tag: Tag is not inside a block comment [$tagLocation]");
                    $newLines[] = $line;

                    continue;
                }

                $scope = new Scope(
                    config: $this->config,
                    className: $className,
                    methodName: $methodName,
                );

                $debugTargetType = $this->getDebugTargetType($scope, $ast, $debugTarget, $autodocCommentNode, $tagLocation);

                if ($debugTargetType) {
                    $path = $this->formatFilePath($filePath, $workingDirectory);
                    $lineNumber = $index + 1;

                    $this->info("$debugTarget [$path:$lineNumber]");

                    $this->printDebugInfo($debugTargetType);
                }
            }
        }
    }

    /**
     * @param Node\Stmt[] $ast
     */
    protected function getDebugTargetType(Scope $scope, array $ast, string $debugTarget, Doc $autodocCommentNode, string $tagLocation): ?Type
    {
        if (preg_match('/^\$(\S+)$/', $debugTarget, $matches)) {
            $node = new Node\Expr\Variable(
                name: $matches[1],
                attributes: [
                    'startLine' => $autodocCommentNode->getStartLine(),
                    'endLine' => $autodocCommentNode->getEndLine(),
                    'startFilePos' => $autodocCommentNode->getStartFilePos(),
                    'endFilePos' => $autodocCommentNode->getEndFilePos(),
                ],
            );

        } else {
            $this->error("Failed to parse @autodoc debug tag: '$debugTarget' is not a PHP variable [$tagLocation]");

            return null;
        }

        if ($scope->methodName) {
            $methodNodeVisitor = new ClassMethodNodeVisitor(
                methodName: $scope->methodName,
                scope: $scope,
                analyzeReturnValue: false,
            );

            $traverser = new NodeTraverser;

            $traverser->addVisitor($methodNodeVisitor);
            $traverser->traverse($ast);
        }

        $unresolvedType = new UnresolvedParserNodeType($node, $scope);

        if ($this->resolutionDepth !== null) {
            for ($i = 0; $i < $this->resolutionDepth; $i++) {
                $unresolvedType = $unresolvedType->unwrapType($scope->config);
            }

            return $unresolvedType;
        }

        return $unresolvedType->deepResolve();
    }

    protected function printDebugInfo(Type $type): void
    {
        dump($type);
    }

    /**
     * @param Node\Stmt[] $ast
     * @return array{?class-string, ?string, ?Doc}
     */
    protected function getTargetClassMethod(array $ast, int $line): array
    {
        $classMethodNodeVisitor = new class ($line) extends NodeVisitorAbstract
        {
            public function __construct(
                private int $targetLine,
            ) {}

            public ?string $className = null;
            public ?string $methodName = null;
            public ?Doc $autodocCommentNode = null;

            /**
             * @return null|NodeVisitor::DONT_TRAVERSE_CHILDREN
             */
            #[Override]
            public function enterNode(Node $node)
            {
                $doc = $node->getDocComment();

                if ($doc && $this->targetLine >= $doc->getStartLine() && $this->targetLine <= $doc->getEndLine()) {
                    $this->autodocCommentNode = $doc;
                }

                if ($node instanceof Node\Stmt\Class_) {
                    $this->className = $node->name?->toString();
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    $startLine = $node->getStartLine();
                    $endLine = $node->getEndLine();

                    if ($this->targetLine >= $startLine && $this->targetLine <= $endLine) {
                        $this->methodName = $node->name->toString();
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;

        $traverser->addVisitor($classMethodNodeVisitor);
        $traverser->traverse($ast);

        if ($classMethodNodeVisitor->className) {
            $traverser = new NodeTraverser;
            $nameResolver = new NameResolver;

            $traverser->addVisitor($nameResolver);
            $traverser->traverse($ast);

            $className = $nameResolver->getResolvedClassName($classMethodNodeVisitor->className);

        } else {
            $className = null;
        }

        return [
            $className,
            $classMethodNodeVisitor->methodName,
            $classMethodNodeVisitor->autodocCommentNode,
        ];
    }

    protected function formatFilePath(string $fullPath, string $workingDirectory): string
    {
        $cwd = getcwd();

        if ($cwd && str_starts_with($fullPath, $cwd)) {
            return ltrim(substr($fullPath, strlen($cwd)), '\\/');
        }

        if (str_starts_with($fullPath, $workingDirectory)) {
            return ltrim(substr($fullPath, strlen($workingDirectory)), '\\/');
        }

        return $fullPath;
    }

    public function log(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function info(string $message): void
    {
        echo PHP_EOL . '[INFO] ' . $message . PHP_EOL . PHP_EOL;
    }

    public function error(string $message): void
    {
        echo PHP_EOL . '[ERROR] ' . $message . PHP_EOL . PHP_EOL;
    }
}
