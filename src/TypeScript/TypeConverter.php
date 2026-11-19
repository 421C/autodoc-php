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
            if ($type->format === 'binary') {
                return $context->isRequest ? 'File' : 'Blob';
            }

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
            return $type->shape ? $this->toTsShape($type, $context) : $this->toTsTypePair($type, $context);
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

            $types = array_map(fn (Type $type) => $this->toTsIntersectionMember($this->convertToTypeScriptType($type, $context)), $type->types);

            return implode('&', array_unique($types));
        }

        return 'unknown';
    }


    private function toTsShape(ArrayType $type, TypeScriptRenderContext $context): string
    {
        $config = $context->scope->config;
        $typeOfEntriesOutsideShape = $type->hasEntriesOutsideShape() ? $type->itemType ?? new UnknownType : null;
        $tsTypes = [];

        if ($type->mayEncodeAsList($config)) {
            $documentedTupleEntries = $type->getDocumentedTupleEntries($config);

            $tsTypes[] = $documentedTupleEntries !== null
                ? $this->toTsTuple($documentedTupleEntries, $context, $typeOfEntriesOutsideShape)
                : $this->toTsList($this->convertToTypeScriptType($type->getTypeOfEveryEntry($config), $context->nested()));
        }

        if ($type->mayEncodeAsObject($config)) {
            $tsTypes[] = $this->toTsObject($type->shape, $context, $typeOfEntriesOutsideShape);
        }

        return implode('|', $tsTypes);
    }


    private function toTsTypePair(ArrayType $type, TypeScriptRenderContext $context): string
    {
        $tsItemType = $this->convertToTypeScriptType($type->itemType ?? new UnknownType, $context->nested());
        $config = $context->scope->config;
        $tsTypes = [];

        if ($type->mayEncodeAsList($config)) {
            $tsTypes[] = $this->toTsList($tsItemType);
        }

        if ($type->mayEncodeAsObject($config)) {
            $tsTypes[] = 'Record<string, ' . $tsItemType . '>';
        }

        return implode('|', $tsTypes);
    }


    /**
     * @param list<Type> $entries
     */
    private function toTsTuple(array $entries, TypeScriptRenderContext $context, ?Type $restEntryType = null): string
    {
        $tsTypes = $this->toTsTupleElements($entries, $restEntryType, $context->nested());

        if (count($tsTypes) < 4 && !str_contains(implode('', $tsTypes), "\n")) {
            return '[' . implode(', ', $tsTypes) . ']';
        }

        $entryBaseIndent = $context->baseIndent . $context->config['indent'];
        $result = '[';

        foreach ($this->toTsTupleElements($entries, $restEntryType, $context->nested($entryBaseIndent)) as $tsType) {
            $result .= "\n" . $entryBaseIndent . $tsType . ',';
        }

        return $result . "\n" . $context->baseIndent . ']';
    }


    /**
     * @param list<Type> $entries
     * @return list<string>
     */
    private function toTsTupleElements(array $entries, ?Type $restEntryType, TypeScriptRenderContext $context): array
    {
        $tsTypes = array_map(
            callback: fn (Type $entry) => $this->toTsTupleElement(
                tsType: $this->convertToTypeScriptType($entry, $context),
                required: $entry->required,
            ),
            array: $entries,
        );

        if ($restEntryType !== null) {
            $tsTypes[] = '...' . $this->toTsList($this->convertToTypeScriptType($restEntryType, $context));
        }

        return $tsTypes;
    }


    private function toTsList(string $tsItemType): string
    {
        if (str_contains($tsItemType, '|') || str_contains($tsItemType, '&') || str_contains($tsItemType, '(') || str_contains($tsItemType, "\n")) {
            return 'Array<' . $tsItemType . '>';
        }

        return $tsItemType . '[]';
    }


    private function toTsTupleElement(string $tsType, bool $required): string
    {
        if ($required) {
            return $tsType;
        }

        if (str_contains($tsType, '|') || str_contains($tsType, '&') || str_contains($tsType, '=>')) {
            return '(' . $tsType . ')?';
        }

        return $tsType . '?';
    }


    private function toTsIntersectionMember(string $tsType): string
    {
        if (str_contains($tsType, '|')) {
            return '(' . $tsType . ')';
        }

        return $tsType;
    }

    /**
     * @param array<int|string, Type> $properties
     */
    private function toTsObject(
        array $properties,
        TypeScriptRenderContext $context,
        ?Type $otherPropertiesType = null,
    ): string {
        $tsConfig = $context->config;
        $baseIndent = $context->baseIndent;
        $propertyBaseIndent = $baseIndent . $tsConfig['indent'];
        $lineEnd = $tsConfig['add_semicolons'] ? ';' : '';

        if ($context->isRootLevel) {
            $properties = $this->projectRootProperties($properties, $context->rootOptions);

            if (isset($context->rootOptions['only'])) {
                $otherPropertiesType = null;
            }
        }

        if (! $properties && $otherPropertiesType === null) {
            return 'object';
        }

        $result = '{';

        foreach ($properties as $propertyName => $propertyType) {
            $tsType = $this->convertToTypeScriptType($propertyType, $context->nested($propertyBaseIndent));

            $propertyName = $this->toTsPropertyName((string) $propertyName, $tsConfig['string_quote']);

            $result .= "\n" . $propertyBaseIndent . $propertyName . ($propertyType->required ? '' : '?') . ': ' . $tsType . $lineEnd;
        }

        if ($otherPropertiesType !== null) {
            $tsIndexSignatureType = $this->toTsTypeEveryPropertyIsAssignableTo($properties, $otherPropertiesType, $context->nested($propertyBaseIndent));

            $result .= "\n" . $propertyBaseIndent . '[key: string]: ' . $tsIndexSignatureType . $lineEnd;
        }

        $result .= "\n" . $baseIndent . '}';

        return $result;
    }


    /**
     * @param array<int|string, Type> $properties
     */
    private function toTsTypeEveryPropertyIsAssignableTo(array $properties, Type $otherPropertiesType, TypeScriptRenderContext $context): string
    {
        $valueTypes = [...array_values($properties), $otherPropertiesType];

        if (array_any($valueTypes, fn (Type $valueType) => $this->mayRenderAsUnknown($valueType, $context))) {
            return 'unknown';
        }

        $tsType = $this->convertToTypeScriptType(new UnionType($valueTypes), $context);

        if (array_any($properties, fn (Type $property) => ! $property->required)) {
            return $tsType . '|undefined';
        }

        return $tsType;
    }


    private function mayRenderAsUnknown(Type $type, TypeScriptRenderContext $context): bool
    {
        $type = $type->unwrapType($context->scope->config);

        if ($type instanceof UnionType) {
            return array_any($type->types, fn (Type $member) => $this->mayRenderAsUnknown($member, $context));
        }

        return $type instanceof UnknownType || $type instanceof NeverType;
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
            return array_filter($properties, fn ($name) => ! in_array($name, $options['omit']), ARRAY_FILTER_USE_KEY);
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
