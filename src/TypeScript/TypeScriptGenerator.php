<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\Analyzer\Scope;
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
        public Config $config,
    ) {
        $this->scope = new Scope($this->config);
        $this->extensionHandler = new ExtensionHandler($this->scope);
    }

    private Scope $scope;
    private ExtensionHandler $extensionHandler;

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

            $type = $phpDoc->createUnresolvedType($phpDoc->createTypeNode($tag->value))->unwrapType($this->config);

            return $this->generateTypeScriptDeclarationFromType($tag, $type);
        }

        $indent = $this->config->data['typescript']['indent'] ?? '    ';
        $baseIndent = $tag->getDeclarationIndent();

        $arguments = preg_split('/\s+/', $tag->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $httpMethod = strtoupper($arguments[0]);
        $routeUri = trim($arguments[1], '/');
        $responseStatusOrRequestKeyword = $arguments[2] ?? null;

        $routeLoader = $this->config->getRouteLoader();
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
        $indent = $this->config->data['typescript']['indent'] ?? '    ';
        $baseIndent = $tag->getDeclarationIndent();

        $tsLines = [];

        if (isset($operation->requestBody->content['application/json']->type)) {
            $type = $operation->requestBody->content['application/json']->type;

            if ($type instanceof ObjectType && $type->typeToDisplay) {
                $type = $type->typeToDisplay->unwrapType($this->config);
            }

            if ($this->isObjectOrArrayShape($type)) {
                $structureType = $tag->getExistingStructureType() ?? 'type';

            } else {
                $structureType = 'type';
            }

            $name = $tag->getExistingStructureName() ?? $this->toPascalCase(basename($route->uri) . 'Request');

            $declarationHeader = ($tag->addExportKeyword ? 'export ' : '')
                . $structureType . ' '
                . $name . ' '
                . ($structureType === 'type' ? '= ' : '');

            $tsLines[] = $baseIndent . $declarationHeader . $this->convertAutoDocTypeToTsType(
                tag: $tag,
                type: $type,
                indent: $indent,
                baseIndent: $baseIndent,
                isRootLevel: true,
            );

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
        $indent = $this->config->data['typescript']['indent'] ?? '    ';
        $baseIndent = $tag->getDeclarationIndent();

        $tsLines = [];

        if (isset($operation->responses[$httpStatus])
            && $operation->responses[$httpStatus] instanceof Response
            && isset($operation->responses[$httpStatus]->content['application/json']->type)
        ) {
            $type = $operation->responses[$httpStatus]->content['application/json']->type->unwrapType($this->config);

            if ($type instanceof ObjectType && $type->typeToDisplay) {
                $type = $type->typeToDisplay->unwrapType($this->config);
            }

            if ($this->isObjectOrArrayShape($type)) {
                $structureType = $tag->getExistingStructureType() ?? 'type';

            } else {
                $structureType = 'type';
            }

            $name = $tag->getExistingStructureName() ?? $this->toPascalCase(basename($route->uri) . 'Response');

            $declarationHeader = ($tag->addExportKeyword ? 'export ' : '')
                . $structureType . ' '
                . $name . ' '
                . ($structureType === 'type' ? '= ' : '');

            $tsLines[] = $baseIndent . $declarationHeader . $this->convertAutoDocTypeToTsType(
                tag: $tag,
                type: $type,
                indent: $indent,
                baseIndent: $baseIndent,
                isRootLevel: true,
            );

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
        $indent = $this->config->data['typescript']['indent'] ?? '    ';
        $baseIndent = $tag->getDeclarationIndent();

        $name = $tag->getExistingStructureName();

        if ($name === null) {
            if ($type instanceof ObjectType && $type->className) {
                $name = PhpClass::basename($type->className);

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
            $tsLines[] = $baseIndent . ($tag->addExportKeyword ? 'export ' : '') . "enum $name {";

            $reflectionEnum = new ReflectionEnum($enumClassName);

            foreach ($reflectionEnum->getCases() as $enumCase) {
                if ($enumCase instanceof ReflectionEnumBackedCase) {
                    $value = $enumCase->getBackingValue();

                } else {
                    $value = $enumCase->name;
                }

                if (is_string($value)) {
                    $value = $this->toTsString($value);
                }

                $tsLines[] = "{$baseIndent}{$indent}{$enumCase->name} = $value,";
            }

            $tsLines[] = $baseIndent . '}';

        } else {
            if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
                $phpClass = new PhpClass($type->className, $this->scope);

                $type = $this->extensionHandler->handleTypeScriptExportExtensions($phpClass, $type);
            }

            if ($type instanceof ObjectType && $type->typeToDisplay) {
                if ($type->typeToDisplay instanceof ObjectType
                    || $type->typeToDisplay instanceof ArrayType
                ) {
                    $type = $type->typeToDisplay;
                }
            }

            $properties = [];

            if ($type instanceof ObjectType) {
                $properties = $type->properties;

            } else if ($type instanceof ArrayType) {
                if ($type->shape) {
                    $properties = $type->shape;
                }
            }

            if (! empty($tag->options['omit'])) {
                foreach ($tag->options['omit'] as $omit) {
                    if (isset($properties[$omit])) {
                        unset($properties[$omit]);

                    } else {
                        $tag->reportError('Property "' . $omit . '" not found in exported type');
                    }
                }
            }

            if ($this->isObjectOrArrayShape($type)) {
                $structureType = $tag->getExistingStructureType() ?? 'type';

            } else {
                $structureType = 'type';
            }

            $declarationHeader = $baseIndent
                . ($tag->addExportKeyword ? 'export ' : '')
                . $structureType . ' '
                . $name . ' '
                . ($structureType === 'type' ? '= ' : '');

            if ($properties) {
                $addSemicolon = $this->config->data['typescript']['add_semicolons'] ?? false;

                $tsLines[] = $declarationHeader . '{';

                foreach ($properties as $propertyName => $propertyType) {
                    $propertyBaseIndent = $baseIndent . $indent;

                    $tsType = $this->convertAutoDocTypeToTsType($tag, $propertyType, $indent, $propertyBaseIndent);

                    $tsLines[] = $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($addSemicolon ? ';' : '');
                }

                $tsLines[] = $baseIndent . '}';

            } else if (! ($type instanceof ObjectType)) {
                $tsLines[] = $declarationHeader . $this->convertAutoDocTypeToTsType(
                    tag: $tag,
                    type: $type,
                    indent: $indent,
                    baseIndent: $baseIndent,
                );

            } else {
                $tsLines[] = $declarationHeader . '{}';
            }
        }

        return $tsLines;
    }


    private function convertAutoDocTypeToTsType(AutoDocTag $tag, Type $type, string $indent, string $baseIndent, bool $isRootLevel = false): string
    {
        $type = $type->unwrapType($this->config);

        if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
            $phpClass = new PhpClass($type->className, $this->scope);

            $type = $this->extensionHandler->handleTypeScriptExportExtensions($phpClass, $type);
        }

        if ($type instanceof IntegerType || $type instanceof NumberType) {
            if ($type->isEnum || ($this->config->data['typescript']['show_values_for_scalar_types'] ?? true)) {
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
            if ($type->isEnum || ($this->config->data['typescript']['show_values_for_scalar_types'] ?? true)) {
                $values = $type->getPossibleValues();

                if ($values) {
                    return implode('|', array_map(fn ($value) => (string) $value, $values));
                }
            }

            return 'number';
        }

        if ($type instanceof StringType) {
            if ($type->isEnum || ($this->config->data['typescript']['show_values_for_scalar_types'] ?? true)) {
                $values = $type->getPossibleValues();

                if ($values) {
                    return implode('|', array_map(fn ($value) => $this->toTsString($value), $values));
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

                if ($isRootLevel && isset($tag->options['omit'])) {
                    $properties = array_filter($type->shape, fn ($name) => ! in_array($name, $tag->options['omit']), ARRAY_FILTER_USE_KEY);

                } else {
                    $properties = $type->shape;
                }

                if (! $properties) {
                    return '{}';
                }

                $result = '{';

                foreach ($properties as $propertyName => $propertyType) {
                    $propertyBaseIndent = $baseIndent . $indent;
                    $addSemicolon = $this->config->data['typescript']['add_semicolons'] ?? false;

                    $tsType = $this->convertAutoDocTypeToTsType($tag, $propertyType, $indent, $propertyBaseIndent);

                    $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($addSemicolon ? ';' : '');
                }

                $result .= "\n" . $baseIndent . '}';

                return $result;
            }

            $keyType = $type->keyType?->unwrapType($this->config);
            $itemType = $type->itemType?->unwrapType($this->config);

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

            if ($isRootLevel && isset($tag->options['omit'])) {
                $properties = array_filter($type->properties, fn ($name) => ! in_array($name, $tag->options['omit']), ARRAY_FILTER_USE_KEY);

            } else {
                $properties = $type->properties;
            }

            if (! $properties) {
                return '{}';
            }

            $result = '{';

            foreach ($properties as $propertyName => $propertyType) {
                $propertyBaseIndent = $baseIndent . $indent;
                $addSemicolon = $this->config->data['typescript']['add_semicolons'] ?? false;

                $tsType = $this->convertAutoDocTypeToTsType($tag, $propertyType, $indent, $propertyBaseIndent);

                $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($addSemicolon ? ';' : '');
            }

            $result .= "\n" . $baseIndent . '}';

            return $result;
        }

        if ($type instanceof UnionType) {
            $type->mergeDuplicateTypes(config: $this->config);

            $types = array_map(fn (Type $type) => $this->convertAutoDocTypeToTsType($tag, $type, $indent, $baseIndent), $type->types);

            return implode('|', array_unique($types));
        }

        if ($type instanceof IntersectionType) {
            $type->mergeDuplicateTypes(mergeAsIntersection: true, config: $this->config);

            $types = array_map(fn (Type $type) => $this->convertAutoDocTypeToTsType($tag, $type, $indent, $baseIndent), $type->types);

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

    private function toTsString(string $input): string
    {
        $quote = $this->config->data['typescript']['string_quote'] ?? "'";

        $escaped = str_replace('\\', '\\\\', $input);
        $escaped = str_replace($quote, '\\' . $quote, $escaped);

        $escaped = str_replace(
            ["\r", "\n", "\t", "\v", "\f", "\0"],
            ['\\r', '\\n', '\\t', '\\v', '\\f', '\\0'],
            $escaped
        );

        return $quote . $escaped . $quote;
    }
}
