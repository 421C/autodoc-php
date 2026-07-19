<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\BoolType;
use AutoDoc\DataTypes\FloatType;
use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\IntersectionType;
use AutoDoc\DataTypes\NeverType;
use AutoDoc\DataTypes\NullType;
use AutoDoc\DataTypes\NumberType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\ScalarType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;

class TypeConverter
{
    public function convertToTypeScriptType(
        Type $type,
        TypeScriptRenderContext $context,
    ): string {
        $scope = $context->scope;
        $tsConfig = $context->config;
        $baseIndent = $context->baseIndent;

        $type = $type->unwrapType($scope->config);

        if (! $context->isRootLevel
            && ($type instanceof ObjectType || $type instanceof ArrayType)
            && $type->className
            && isset($context->namedTypes[ltrim($type->className, '\\')])
        ) {
            return $context->namedTypes[ltrim($type->className, '\\')];
        }

        if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
            $phpClass = new PhpClass($type->className, $scope);

            $type = $scope->extensions->handleTypeScriptExportExtensions($phpClass, $type);
        }

        if ($type instanceof IntegerType || $type instanceof NumberType) {
            if ($type->isEnum || $tsConfig['show_values_for_scalar_types']) {
                $values = $type->getPossibleValues();

                if ($values && ScalarType::canRepresentLiteralValues($values)) {
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

                if ($values && ScalarType::canRepresentLiteralValues($values)) {
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

        if ($type instanceof NeverType) {
            return ($scope->config->data['intersections']['render_empty_as_unknown'] ?? true) ? 'unknown' : 'never';
        }

        if ($type instanceof ArrayType) {
            if ($type->shape) {
                if (array_is_list($type->shape) && !in_array(false, array_column($type->shape, 'required'))) {
                    $tsTypes = array_map(fn ($value) => $this->convertToTypeScriptType($value, $context->nested()), $type->shape);

                    if (count($type->shape) < 4 && !str_contains(implode('', $tsTypes), "\n")) {
                        return '[' . implode(', ', $tsTypes) . ']';

                    } else {
                        $result = '[';

                        foreach ($type->shape as $propertyType) {
                            $propertyBaseIndent = $baseIndent . $tsConfig['indent'];

                            $tsType = $this->convertToTypeScriptType($propertyType, $context->nested($propertyBaseIndent));

                            $result .= "\n" . $propertyBaseIndent . $tsType . ',';
                        }

                        $result .= "\n" . $baseIndent . ']';

                        return $result;
                    }
                }

                return $this->toTsObject($type->shape, $context);
            }

            $keyType = $type->keyType?->unwrapType($scope->config);
            $itemType = $type->itemType?->unwrapType($scope->config);

            $tsItemType = $this->convertToTypeScriptType($itemType ?? new UnknownType, $context->nested());

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
                return $this->convertToTypeScriptType($type->typeToDisplay, $context);
            }

            return $this->toTsObject($type->properties, $context);
        }

        if ($type instanceof UnionType) {
            $type->mergeDuplicateTypes(config: $scope->config);

            $types = array_unique(array_map(fn (Type $type) => $this->convertToTypeScriptType($type, $context), $type->types));

            // TS `unknown` already includes null, so `unknown|null` is just noise.
            if (count($types) > 1 && in_array('unknown', $types, true)) {
                $types = array_filter($types, fn (string $tsType) => $tsType !== 'null');
            }

            return implode('|', $types);
        }

        if ($type instanceof IntersectionType) {
            $type->mergeDuplicateTypes(config: $scope->config, mergeAsIntersection: true);

            $types = array_map(fn (Type $type) => $this->convertToTypeScriptType($type, $context), $type->types);

            return implode('&', array_unique($types));
        }

        return 'unknown';
    }

    /**
     * @param array<int|string, Type> $properties
     */
    private function toTsObject(
        array $properties,
        TypeScriptRenderContext $context,
    ): string {
        $tsConfig = $context->config;
        $baseIndent = $context->baseIndent;

        if ($context->isRootLevel) {
            $properties = $this->projectRootProperties($properties, $context->rootOptions);
        }

        if (! $properties) {
            return 'object';
        }

        $result = '{';

        foreach ($properties as $propertyName => $propertyType) {
            $propertyBaseIndent = $baseIndent . $tsConfig['indent'];

            $tsType = $this->convertToTypeScriptType($propertyType, $context->nested($propertyBaseIndent));

            $propertyName = $this->toTsPropertyName((string) $propertyName, $tsConfig['string_quote']);

            $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . ($tsConfig['add_semicolons'] ? ';' : '');
        }

        $result .= "\n" . $baseIndent . '}';

        return $result;
    }

    /**
     * @param array<int|string, Type> $properties
     * @param array{
     *     omit?: string[],
     *     only?: string[],
     *     with?: array<int|string, Type>,
     * } $options
     * @return array<int|string, Type>
     */
    private function projectRootProperties(array $properties, array $options): array
    {
        if (isset($options['only'])) {
            $properties = array_filter($properties, fn ($name) => in_array($name, $options['only']), ARRAY_FILTER_USE_KEY);
        }

        foreach ($options['with'] ?? [] as $propertyName => $propertyType) {
            $properties[$propertyName] = $propertyType;
        }

        if (isset($options['omit'])) {
            $properties = array_filter($properties, fn ($name) => ! in_array($name, $options['omit']), ARRAY_FILTER_USE_KEY);
        }

        return $properties;
    }


    public function toTsString(string $input, string $quote): string
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
