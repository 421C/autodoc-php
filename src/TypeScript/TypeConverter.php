<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpClass;
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

/**
 * @phpstan-import-type TypeScriptConfig from Config
 */
class TypeConverter
{
    /**
     * @param TypeScriptConfig $tsConfig
     */
    public function convertToTypeScriptType(
        Type $type,
        Scope $scope,
        array $tsConfig,
        string $baseIndent,
        ?AutoDocTag $tag = null,
        bool $isRootLevel = false,
    ): string {

        $type = $type->unwrapType($scope->config);

        if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
            $phpClass = new PhpClass($type->className, $scope);

            $type = (new ExtensionHandler($scope))->handleTypeScriptExportExtensions($phpClass, $type);
        }

        if ($type instanceof IntegerType || $type instanceof NumberType) {
            if ($type->isEnum || $tsConfig['show_values_for_scalar_types']) {
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
            if ($type->isEnum || $tsConfig['show_values_for_scalar_types']) {
                $values = $type->getPossibleValues();

                if ($values) {
                    return implode('|', array_map(fn ($value) => (string) $value, $values));
                }
            }

            return 'number';
        }

        if ($type instanceof StringType) {
            if ($type->isEnum || $tsConfig['show_values_for_scalar_types']) {
                $values = $type->getPossibleValues();
                $stringQuote = $tsConfig['string_quote'];

                if ($values) {
                    return implode('|', array_map(fn ($value) => $this->toTsString($value, $stringQuote), $values));
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
                    $tsTypes = array_map(fn ($value) => $this->convertToTypeScriptType($value, $scope, $tsConfig, $baseIndent, $tag), $type->shape);

                    if (count($type->shape) < 4 && !str_contains(implode('', $tsTypes), "\n")) {
                        return '[' . implode(', ', $tsTypes) . ']';

                    } else {
                        $result = '[';

                        foreach ($type->shape as $propertyType) {
                            $propertyBaseIndent = $baseIndent . $tsConfig['indent'];

                            $tsType = $this->convertToTypeScriptType($propertyType, $scope, $tsConfig, $propertyBaseIndent, $tag);

                            $result .= "\n" . $propertyBaseIndent . $tsType . ',';
                        }

                        $result .= "\n" . $baseIndent . ']';
                    }
                }

                return $this->toTsObject($type->shape, $scope, $tsConfig, $baseIndent, $tag, $isRootLevel);
            }

            $keyType = $type->keyType?->unwrapType($scope->config);
            $itemType = $type->itemType?->unwrapType($scope->config);

            $tsItemType = $this->convertToTypeScriptType($itemType ?? new UnknownType, $scope, $tsConfig, $baseIndent, $tag);

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
                return $this->convertToTypeScriptType($type->typeToDisplay, $scope, $tsConfig, $baseIndent, $tag, $isRootLevel);
            }

            return $this->toTsObject($type->properties, $scope, $tsConfig, $baseIndent, $tag, $isRootLevel);
        }

        if ($type instanceof UnionType) {
            $type->mergeDuplicateTypes(config: $scope->config);

            $types = array_map(fn (Type $type) => $this->convertToTypeScriptType($type, $scope, $tsConfig, $baseIndent, $tag, $isRootLevel), $type->types);

            return implode('|', array_unique($types));
        }

        if ($type instanceof IntersectionType) {
            $type->mergeDuplicateTypes(mergeAsIntersection: true, config: $scope->config);

            $types = array_map(fn (Type $type) => $this->convertToTypeScriptType($type, $scope, $tsConfig, $baseIndent, $tag, $isRootLevel), $type->types);

            return implode('&', array_unique($types));
        }

        return 'unknown';
    }

    /**
     * @param array<int|string, Type> $properties
     * @param TypeScriptConfig $tsConfig
     */
    private function toTsObject(array $properties, Scope $scope, array $tsConfig, string $baseIndent, ?AutoDocTag $tag, bool $isRootLevel): string
    {
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
            $propertyBaseIndent = $baseIndent . $tsConfig['indent'];

            $tsType = $this->convertToTypeScriptType($propertyType, $scope, $tsConfig, $propertyBaseIndent, $tag);

            $propertyName = $this->toTsPropertyName((string) $propertyName, $tsConfig['string_quote']);

            $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($tsConfig['add_semicolons'] ? ';' : '');
        }

        $result .= "\n" . $baseIndent . '}';

        return $result;
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


    private function toTsPropertyName(string $input, string $quote): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $input)) {
            return $input;
        }

        return $quote . $input . $quote;
    }
}
