<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\Config;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;
use AutoDoc\ExtensionHandler;
use AutoDoc\OpenApi\Operation;
use AutoDoc\OpenApi\Response;
use AutoDoc\Route;
use Exception;
use ReflectionEnum;
use ReflectionEnumBackedCase;

class TypeScriptGenerator
{
    public function __construct(
        /**
         * @deprecated
         */
        public ?Config $config2 = null,
    ) {}

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
                $tsLines = $this->generateTypeScriptDeclarationFromRequestBody($tag, $operation, $route);

            } else {
                if ($responseStatusOrRequestKeyword && ! str_contains($responseStatusOrRequestKeyword, '{')) {
                    $httpStatus = $responseStatusOrRequestKeyword;

                } else {
                    $httpStatus = array_key_first($operation->responses ?? []);
                }

                if ($httpStatus === null) {
                    $tag->reportError('Response not found for route "' . $httpMethod . ' /' . trim($route->uri, '/') . '"');

                } else {
                    $tsLines = $this->generateTypeScriptDeclarationFromResponse($tag, $operation, $route, $httpStatus);
                }
            }

        } else {
            $tag->reportError('Route "' . $httpMethod . ' /' . $routeUri . '" not found');
        }

        return $tsLines;
    }

    /**
     * @return string[]
     */
    private function generateTypeScriptDeclarationFromRequestBody(AutoDocTag $tag, Operation $operation, Route $route): array
    {
        $indent = $tag->getConfig('indent');
        $baseIndent = $tag->getDeclarationIndent();

        $tsLines = [];

        if (isset($operation->requestBody->content['application/json']->type)) {
            $type = $operation->requestBody->content['application/json']->type;

            if ($type instanceof ObjectType && $type->typeToDisplay) {
                $type = $type->typeToDisplay->unwrapType($tag->scope->config);
            }

            if ($this->isObjectOrArrayShape($type)) {
                $structureType = $tag->getExistingStructureType() ?? 'type';

            } else {
                $structureType = 'type';
            }

            $name = $tag->getExistingStructureName() ?? $this->toPascalCase(basename($route->uri) . 'Request');
            $declarationHeader = $this->generateDeclarationHeader($tag->addExportKeyword, $name, $structureType);

            $typeDefinition = $this->convertAutoDocTypeToTsType(
                tag: $tag,
                type: $type,
                indent: $indent,
                baseIndent: $baseIndent,
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

            $tsLines[] = $baseIndent . $declarationHeader . ($importStatement ?? $typeDefinition);

        } else {
            $tag->reportError('Request body not found for route "' . strtoupper($route->method) . ' /' . trim($route->uri, '/') . '"');
        }

        return $tsLines;
    }

    /**
     * @return string[]
     */
    private function generateTypeScriptDeclarationFromResponse(AutoDocTag $tag, Operation $operation, Route $route, int|string $httpStatus): array
    {
        $indent = $tag->getConfig('indent');
        $baseIndent = $tag->getDeclarationIndent();

        $tsLines = [];

        if (isset($operation->responses[$httpStatus])
            && $operation->responses[$httpStatus] instanceof Response
            && isset($operation->responses[$httpStatus]->content['application/json']->type)
        ) {
            $type = $operation->responses[$httpStatus]->content['application/json']->type->unwrapType($tag->scope->config);

            if ($type instanceof ObjectType && $type->typeToDisplay) {
                $type = $type->typeToDisplay->unwrapType($tag->scope->config);
            }

            if ($this->isObjectOrArrayShape($type)) {
                $structureType = $tag->getExistingStructureType() ?? 'type';

            } else {
                $structureType = 'type';
            }

            $name = $tag->getExistingStructureName() ?? $this->toPascalCase(basename($route->uri) . 'Response');
            $declarationHeader = $this->generateDeclarationHeader($tag->addExportKeyword, $name, $structureType);

            $typeDefinition = $this->convertAutoDocTypeToTsType(
                tag: $tag,
                type: $type,
                indent: $indent,
                baseIndent: $baseIndent,
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

            $tsLines[] = $baseIndent . $declarationHeader . ($importStatement ?? $typeDefinition);

        } else {
            $tag->reportError('Response status "' . $httpStatus . '" not found for route "' . strtoupper($route->method) . ' /' . trim($route->uri, '/') . '"');
        }

        return $tsLines;
    }

    /**
     * @return string[]
     */
    private function generateTypeScriptDeclarationFromType(AutoDocTag $tag, Type $type): array
    {
        $indent = $tag->getConfig('indent');
        $baseIndent = $tag->getDeclarationIndent();

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

        $writeInSeparateTsFile = $tag->getConfig('save_types_in_single_file');
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

            $typeDefinition = $this->convertAutoDocTypeToTsType(
                tag: $tag,
                type: $type,
                indent: $indent,
                baseIndent: $baseIndent,
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


    private function convertAutoDocTypeToTsType(AutoDocTag $tag, Type $type, string $indent, string $baseIndent, bool $isRootLevel = false): string
    {
        $type = $type->unwrapType($tag->scope->config);

        if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
            $phpClass = new PhpClass($type->className, $tag->scope);

            $type = (new ExtensionHandler($tag->scope))->handleTypeScriptExportExtensions($phpClass, $type);
        }

        if ($type instanceof IntegerType || $type instanceof NumberType) {
            if ($type->isEnum || $tag->getConfig('show_values_for_scalar_types')) {
                $values = $type->getPossibleValues();

                if ($values) {
                    return implode('|', array_map(fn ($value) => (string) $value, $values));
                }
            }

            if ($type->isString) {
                return 'string';
            }

            return 'number';
        }

        if ($type instanceof FloatType) {
            if ($type->isEnum || $tag->getConfig('show_values_for_scalar_types')) {
                $values = $type->getPossibleValues();

                if ($values) {
                    return implode('|', array_map(fn ($value) => (string) $value, $values));
                }
            }

            return 'number';
        }

        if ($type instanceof StringType) {
            if ($type->isEnum || $tag->getConfig('show_values_for_scalar_types')) {
                $values = $type->getPossibleValues();

                if ($values) {
                    return implode('|', array_map(fn ($value) => $this->toTsString($value, $tag->getConfig('string_quote')), $values));
                }
            }

            return 'string';
        }

        if ($type instanceof BoolType) {
            if ($type->value !== null) {
                return $type->value ? 'true' : 'false';
            }

            return 'boolean';
        }

        if ($type instanceof NullType) {
            return 'null';
        }

        if ($type instanceof ArrayType) {
            if ($type->shape) {
                if (array_is_list($type->shape) && !in_array(false, array_column($type->shape, 'required'))) {
                    $tsTypes = array_map(fn ($value) => $this->convertAutoDocTypeToTsType($tag, $value, $indent, $baseIndent), $type->shape);

                    if (count($type->shape) < 4 && !str_contains(implode('', $tsTypes), "\n")) {
                        return '[' . implode(', ', $tsTypes) . ']';

                    } else {
                        $result = '[';

                        foreach ($type->shape as $propertyType) {
                            $propertyBaseIndent = $baseIndent . $indent;

                            $tsType = $this->convertAutoDocTypeToTsType($tag, $propertyType, $indent, $propertyBaseIndent);

                            $result .= "\n" . $propertyBaseIndent . $tsType . ',';
                        }

                        $result .= "\n" . $baseIndent . ']';
                    }
                }

                $properties = $type->shape;

                if ($isRootLevel) {
                    if (isset($tag->options['only'])) {
                        $properties = array_filter($properties, fn ($name) => in_array($name, $tag->options['only']), ARRAY_FILTER_USE_KEY);
                    }

                    if (! empty($tag->options['with'])) {
                        foreach ($tag->options['with'] as $propName => $propType) {
                            $properties[$propName] = $propType;
                        }
                    }

                    if (isset($tag->options['omit'])) {
                        $properties = array_filter($properties, fn ($name) => ! in_array($name, $tag->options['omit']), ARRAY_FILTER_USE_KEY);
                    }
                }

                if (! $properties) {
                    return '{}';
                }

                $result = '{';

                foreach ($properties as $propertyName => $propertyType) {
                    $propertyBaseIndent = $baseIndent . $indent;
                    $addSemicolon = $tag->getConfig('add_semicolons');

                    $tsType = $this->convertAutoDocTypeToTsType($tag, $propertyType, $indent, $propertyBaseIndent);

                    $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($addSemicolon ? ';' : '');
                }

                $result .= "\n" . $baseIndent . '}';

                return $result;
            }

            $keyType = $type->keyType?->unwrapType($tag->scope->config);
            $itemType = $type->itemType?->unwrapType($tag->scope->config);

            $tsItemType = $this->convertAutoDocTypeToTsType($tag, $itemType ?? new UnknownType, $indent, $baseIndent);

            if ($keyType && !($keyType instanceof IntegerType)) {
                return 'Record<string, ' . $tsItemType . '>';
            }

            if (str_contains($tsItemType, '|') || str_contains($tsItemType, '&') || str_contains($tsItemType, '(') || str_contains($tsItemType, "\n")) {
                return 'Array<' . $tsItemType . '>';
            }

            return $tsItemType . '[]';
        }

        if ($type instanceof ObjectType) {
            if ($type->typeToDisplay) {
                return $this->convertAutoDocTypeToTsType($tag, $type->typeToDisplay, $indent, $baseIndent, $isRootLevel);
            }

            $properties = $type->properties;

            if ($isRootLevel) {
                if (isset($tag->options['only'])) {
                    $properties = array_filter($properties, fn ($name) => in_array($name, $tag->options['only']), ARRAY_FILTER_USE_KEY);
                }

                if (! empty($tag->options['with'])) {
                    foreach ($tag->options['with'] as $propName => $propType) {
                        $properties[$propName] = $propType;
                    }
                }

                if (isset($tag->options['omit'])) {
                    $properties = array_filter($properties, fn ($name) => ! in_array($name, $tag->options['omit']), ARRAY_FILTER_USE_KEY);
                }
            }

            if (! $properties) {
                return '{}';
            }

            $result = '{';

            foreach ($properties as $propertyName => $propertyType) {
                $propertyBaseIndent = $baseIndent . $indent;
                $addSemicolon = $tag->getConfig('add_semicolons');

                $tsType = $this->convertAutoDocTypeToTsType($tag, $propertyType, $indent, $propertyBaseIndent);

                $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($addSemicolon ? ';' : '');
            }

            $result .= "\n" . $baseIndent . '}';

            return $result;
        }

        if ($type instanceof UnionType) {
            $type->mergeDuplicateTypes(config: $tag->scope->config);

            $types = array_map(fn (Type $type) => $this->convertAutoDocTypeToTsType($tag, $type, $indent, $baseIndent, $isRootLevel), $type->types);

            return implode('|', array_unique($types));
        }

        if ($type instanceof IntersectionType) {
            $type->mergeDuplicateTypes(mergeAsIntersection: true, config: $tag->scope->config);

            $types = array_map(fn (Type $type) => $this->convertAutoDocTypeToTsType($tag, $type, $indent, $baseIndent, $isRootLevel), $type->types);

            return implode('&', array_unique($types));
        }

        return 'unknown';
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
