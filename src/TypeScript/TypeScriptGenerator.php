<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\ExtensionHandler;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Response;
use AutoDoc\Route;
use Exception;
use ReflectionEnum;
use ReflectionEnumBackedCase;

class TypeScriptGenerator
{
    public function __construct()
    {
        $this->typeConverter = new TypeConverter;
    }

    private TypeConverter $typeConverter;

    /**
     * @var array<string, string[]>
     */
    private array $filesToGenerate = [];

    /**
     * @return string[]
     */
    public function generateTypeScriptDeclaration(AutoDocTag $tag): array
    {
        if (empty($tag->value)) {
            throw new Exception('Missing argument after @autodoc tag');
        }

        if (! preg_match('/^(GET|HEAD|POST|PUT|DELETE|PATCH|CONNECT|OPTIONS|TRACE)\s+(.*)/i', $tag->value)) {
            $phpDoc = new PhpDoc(
                docComment: '/** ' . ' */',
                scope: $tag->scope,
            );

            $type = $phpDoc->createUnresolvedType($phpDoc->createTypeNode($tag->value))->unwrapType($tag->scope->config);

            return $this->generateTypeScriptDeclarationFromType($tag, $type);
        }

        $indent = $tag->getConfig('indent');
        $baseIndent = $tag->getDeclarationIndent();

        $arguments = preg_split('/\s+/', $tag->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $httpMethod = strtoupper($arguments[0]);
        $routeUri = trim($arguments[1], '/');
        $responseStatusOrRequestKeyword = $arguments[2] ?? null;

        $routeLoader = $tag->scope->config->getRouteLoader();
        $route = null;
        $operation = null;

        $tsLines = [];

        foreach ($routeLoader->getRoutes() as $routeToCheck) {
            if (trim($routeToCheck->uri, '/') === $routeUri && strtoupper($routeToCheck->method) === $httpMethod) {
                $route = $routeToCheck;
                $operation = $routeLoader->routeToOperation($route);
            }
        }

        if ($route && $operation) {
            if ($responseStatusOrRequestKeyword === 'request') {
                $tsLines = [
                    $this->generateTypeScriptDeclarationFromRequestBody($tag, $operation, $route),
                ];

            } else {
                if ($responseStatusOrRequestKeyword && ! str_contains($responseStatusOrRequestKeyword, '{')) {
                    $httpStatus = $responseStatusOrRequestKeyword;

                } else if (isset($operation->responses[200]) || isset($operation->responses['200'])) {
                    $httpStatus = 200;

                } else {
                    $httpStatus = array_key_first($operation->responses ?? []);
                }

                if ($httpStatus === null) {
                    $tag->reportError('Response not found for route "' . $httpMethod . ' /' . trim($route->uri, '/') . '"');

                } else {
                    $tsLines = [
                        $this->generateTypeScriptDeclarationFromResponse($tag, $operation, $route, $httpStatus),
                    ];
                }
            }

        } else {
            $tag->reportError('Route "' . $httpMethod . ' /' . $routeUri . '" not found');
        }

        return $tsLines;
    }


    private function generateTypeScriptDeclarationFromRequestBody(AutoDocTag $tag, Operation $operation, Route $route): string
    {
        $indent = $tag->getConfig('indent');
        $baseIndent = $tag->getDeclarationIndent();

        if (! $operation->requestBody) {
            $tag->reportError('Request body not found for route "' . strtoupper($route->method) . ' /' . trim($route->uri, '/') . '"');

            return '';
        }

        $type = $operation->requestBody->content['application/json']->type
            ?? $operation->requestBody->content[array_key_first($operation->requestBody->content) ?? '']->type
            ?? null;

        if (! $type) {
            $tag->reportError('Request type not found for route "' . strtoupper($route->method) . ' /' . trim($route->uri, '/') . '"');

            return '';
        }

        $type = $type->unwrapType($tag->scope->config);

        if ($type instanceof ObjectType && $type->typeToDisplay) {
            $type = $type->typeToDisplay->unwrapType($tag->scope->config);
        }

        if ($this->isObjectOrArrayShape($type)) {
            $structureType = $tag->getExistingStructureType() ?? 'type';

        } else {
            $structureType = 'type';
        }

        $lastPartOfUri = preg_replace('/[^a-zA-Z]/', ' ', basename($route->uri));
        $name = $tag->getExistingStructureName() ?? $this->toPascalCase($lastPartOfUri . 'Request');
        $declarationHeader = $this->generateDeclarationHeader($tag->addExportKeyword, $name, $structureType);

        $typeDefinition = $this->typeConverter->convertToTypeScriptType(
            type: $type,
            scope: $tag->scope,
            tsConfig: $tag->getConfig(),
            baseIndent: $baseIndent,
            tag: $tag,
            isRootLevel: true,
        );

        $importStatement = null;
        $writeInSeparateTsFile = $tag->getConfig('save_types_in_single_file');

        if ($writeInSeparateTsFile) {
            $this->prepareDeclarationToBeWrittenToFile(
                $tag,
                $tag->options['as'] ?? $name,
                $writeInSeparateTsFile,
                $baseIndent . $this->generateDeclarationHeader(true, $tag->options['as'] ?? $name, $structureType) . $typeDefinition,
            );

            $importStatement = $this->generateImportStatement($tag, $name, $writeInSeparateTsFile);
        }

        return $baseIndent . $declarationHeader . ($importStatement ?? $typeDefinition);
    }


    private function generateTypeScriptDeclarationFromResponse(AutoDocTag $tag, Operation $operation, Route $route, int|string $httpStatus): string
    {
        $indent = $tag->getConfig('indent');
        $baseIndent = $tag->getDeclarationIndent();

        if (! isset($operation->responses[$httpStatus])
            || ! ($operation->responses[$httpStatus] instanceof Response)
        ) {
            $tag->reportError('Response status "' . $httpStatus . '" not found for route "' . strtoupper($route->method) . ' /' . trim($route->uri, '/') . '"');

            return '';
        }

        $type = $operation->responses[$httpStatus]->content['application/json']->type
            ?? $operation->responses[$httpStatus]->content[array_key_first($operation->responses[$httpStatus]->content) ?? '']->type
            ?? null;

        if (! $type) {
            $tag->reportError('Response type not found for route "' . strtoupper($route->method) . ' /' . trim($route->uri, '/') . '"');

            return '';
        }

        $type = $type->unwrapType($tag->scope->config);

        if ($type instanceof ObjectType && $type->typeToDisplay) {
            $type = $type->typeToDisplay->unwrapType($tag->scope->config);
        }

        if ($this->isObjectOrArrayShape($type)) {
            $structureType = $tag->getExistingStructureType() ?? 'type';

        } else {
            $structureType = 'type';
        }

        $lastPartOfUri = preg_replace('/[^a-zA-Z]/', ' ', basename($route->uri));
        $name = $tag->getExistingStructureName() ?? $this->toPascalCase($lastPartOfUri . 'Response');
        $declarationHeader = $this->generateDeclarationHeader($tag->addExportKeyword, $name, $structureType);

        $typeDefinition = $this->typeConverter->convertToTypeScriptType(
            type: $type,
            scope: $tag->scope,
            tsConfig: $tag->getConfig(),
            baseIndent: $baseIndent,
            tag: $tag,
            isRootLevel: true,
        );

        $importStatement = null;
        $writeInSeparateTsFile = $tag->getConfig('save_types_in_single_file');

        if ($writeInSeparateTsFile) {
            $this->prepareDeclarationToBeWrittenToFile(
                $tag,
                $tag->options['as'] ?? $name,
                $writeInSeparateTsFile,
                $baseIndent . $this->generateDeclarationHeader(true, $tag->options['as'] ?? $name, $structureType) . $typeDefinition,
            );

            $importStatement = $this->generateImportStatement($tag, $name, $writeInSeparateTsFile);
        }

        return $baseIndent . $declarationHeader . ($importStatement ?? $typeDefinition);
    }

    /**
     * @return string[]
     */
    private function generateTypeScriptDeclarationFromType(AutoDocTag $tag, Type $type): array
    {
        $baseIndent = $tag->getDeclarationIndent();
        $indent = $tag->getConfig('indent');
        $writeInSeparateTsFile = $tag->getConfig('save_types_in_single_file');

        $name = $tag->getExistingStructureName();

        if ($name === null) {
            if ($type instanceof ObjectType && $type->className) {
                $name = PhpClass::basename($type->className);

            } else if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tag->value)) {
                $name = $tag->value;

            } else {
                $name = 'UnnamedType';
            }
        }

        $enumClassName = null;

        if ($type instanceof ObjectType && $type->className) {
            if (enum_exists($type->className)) {
                $enumClassName = $type->className;
            }
        }

        $tsLines = [];

        if ($enumClassName) {
            $reflectionEnum = new ReflectionEnum($enumClassName);
            $enumCaseDefinitions = [];

            foreach ($reflectionEnum->getCases() as $enumCase) {
                if (isset($tag->options['only']) && ! in_array($enumCase->name, $tag->options['only'])) {
                    continue;
                }

                if (isset($tag->options['omit']) && in_array($enumCase->name, $tag->options['omit'])) {
                    continue;
                }

                if ($enumCase instanceof ReflectionEnumBackedCase) {
                    $value = $enumCase->getBackingValue();

                } else {
                    $value = $enumCase->name;
                }

                if (is_string($value)) {
                    $value = $this->toTsString($value, $tag->getConfig('string_quote'));
                }

                $enumCaseDefinitions[] = "{$baseIndent}{$indent}{$enumCase->name} = $value,";
            }

            $typeDefinition = $enumCaseDefinitions
                ? '{' . "\n" . implode("\n", $enumCaseDefinitions) . "\n" . $baseIndent . '}'
                : '{}';

            $importStatement = null;

            if ($writeInSeparateTsFile) {
                $this->prepareDeclarationToBeWrittenToFile(
                    $tag,
                    $tag->options['as'] ?? $name,
                    $writeInSeparateTsFile,
                    $baseIndent . 'export enum ' . ($tag->options['as'] ?? $name) . ' ' . $typeDefinition,
                );

                $importStatement = $this->generateImportStatement($tag, $name, $writeInSeparateTsFile);

                $tsLines[] = $baseIndent . ($tag->addExportKeyword ? 'export ' : '') . "type $name = " . $importStatement;

            } else {
                $tsLines[] = $baseIndent . ($tag->addExportKeyword ? 'export ' : '') . "enum $name " . $typeDefinition;
            }

        } else {
            if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
                $phpClass = new PhpClass($type->className, $tag->scope);

                $type = (new ExtensionHandler($tag->scope))->handleTypeScriptExportExtensions($phpClass, $type);
            }

            if ($type instanceof ObjectType && $type->typeToDisplay) {
                if ($type->typeToDisplay instanceof ObjectType
                    || $type->typeToDisplay instanceof ArrayType
                ) {
                    $type = $type->typeToDisplay;
                }
            }

            if ($type instanceof ObjectType) {
                $type->typeToDisplay = null;
            }

            if ($this->isObjectOrArrayShape($type) && !$writeInSeparateTsFile) {
                $structureType = $tag->getExistingStructureType() ?? 'type';

            } else {
                $structureType = 'type';
            }

            $declarationHeader = $this->generateDeclarationHeader($tag->addExportKeyword, $name, $structureType);

            $typeDefinition = $this->typeConverter->convertToTypeScriptType(
                type: $type,
                scope: $tag->scope,
                tsConfig: $tag->getConfig(),
                baseIndent: $baseIndent,
                tag: $tag,
                isRootLevel: true,
            );

            $importStatement = null;

            if ($writeInSeparateTsFile) {
                $this->prepareDeclarationToBeWrittenToFile(
                    $tag,
                    $tag->options['as'] ?? $name,
                    $writeInSeparateTsFile,
                    $baseIndent . $this->generateDeclarationHeader(true, $tag->options['as'] ?? $name, $structureType) . $typeDefinition,
                );

                $importStatement = $this->generateImportStatement($tag, $name, $writeInSeparateTsFile);
            }

            $tsLines[] = $baseIndent . $declarationHeader . ($importStatement ?? $typeDefinition);
        }

        return $tsLines;
    }


    private function isObjectOrArrayShape(Type $type): bool
    {
        return $type instanceof ObjectType || ($type instanceof ArrayType && $type->shape && !array_is_list($type->shape));
    }

    private function toPascalCase(string $input): string
    {
        $input = str_replace(['-', '_'], ' ', $input);
        $input = str_replace(' ', '', ucwords($input));

        return $input;
    }

    private function toTsString(string $input, string $quote): string
    {
        $escaped = str_replace('\\', '\\\\', $input);
        $escaped = str_replace($quote, '\\' . $quote, $escaped);

        $escaped = str_replace(
            ["\r", "\n", "\t", "\v", "\f", "\0"],
            ['\\r', '\\n', '\\t', '\\v', '\\f', '\\0'],
            $escaped
        );

        return $quote . $escaped . $quote;
    }


    private function generateDeclarationHeader(bool $export, string $name, string $structureType): string
    {
        return ($export ? 'export ' : '')
            . $structureType . ' '
            . $name . ' '
            . ($structureType === 'type' ? '= ' : '');
    }

    private function prepareDeclarationToBeWrittenToFile(AutoDocTag $tag, string $name, string $filePath, string $typeDefinition): void
    {
        $fullPath = null;

        foreach ($tag->getConfig('path_prefixes') as $prefix => $basePath) {
            if (str_starts_with($filePath, $prefix)) {
                $fullPath = $basePath . substr($filePath, strlen($prefix));
                break;
            }
        }

        if ($fullPath === null) {
            $tag->reportError('No matching path prefix found for path "' . $filePath . '". Check your path_prefixes configuration.');

            return;
        }

        if (isset($this->filesToGenerate[$fullPath][$name])) {
            $tag->reportError('Type "' . $name . '" is already exported in file "' . $fullPath . '". Use `as` option to export type with a different name.');

            return;
        }

        $this->filesToGenerate[$fullPath][$name] = $typeDefinition;
    }

    private function generateImportStatement(AutoDocTag $tag, string $name, string $filePath): string
    {
        return 'import(' . $this->toTsString($filePath, $tag->getConfig('string_quote')) . ').' . ($tag->options['as'] ?? $name);
    }

    public function overwriteGeneratedFiles(): void
    {
        $filePrefix = '/**' . "\n"
            . ' * This file is auto-generated by PHP AutoDoc.' . "\n"
            . ' * Documentation: https://phpautodoc.com/docs/typescript' . "\n"
            . ' */' . "\n\n";

        foreach ($this->filesToGenerate as $filePath => $typeDefinitions) {
            file_put_contents($filePath, $filePrefix . implode("\n\n", array_map($this->normalizeIndent(...), $typeDefinitions)));
        }
    }

    private function normalizeIndent(string $text): string
    {
        $lines = explode("\n", $text);
        $indents = [];

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^([ \t]+)(?=\S)/', $line, $m)) {
                $indents[] = $m[1];
            } else {
                return $text;
            }
        }

        if (empty($indents)) {
            return $text;
        }

        $common = $indents[0];

        foreach ($indents as $indent) {
            while (! str_starts_with($indent, $common)) {
                $common = substr($common, 0, -1);
            }

            if ($common === '') {
                return $text;
            }
        }

        return preg_replace('/^' . preg_quote($common, '/') . '/m', '', $text) ?? $text;
    }
}
