<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpDoc;
use AutoDoc\Analyzer\Scope;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use Exception;

/**
 * @phpstan-import-type AutoDocTagOptions from ParsedAutoDocTag
 */
class AutoDocTagParser
{
    public function parse(string $value, Scope $scope): ParsedAutoDocTag
    {
        if (! preg_match('/^(.*)\s+(\{.*\})\s*/s', $value, $matches)) {
            return new ParsedAutoDocTag($scope, $value);
        }

        $tagValue = $matches[1];
        $optionsString = $matches[2];
        $phpDoc = new PhpDoc('/**  */', $scope);
        $optionsType = $phpDoc->createUnresolvedType($phpDoc->createTypeNode('array' . $optionsString))->resolve();

        if (! $optionsType instanceof ArrayType || empty($optionsType->shape)) {
            throw new Exception('Failed to parse @autodoc tag options: ' . $optionsString);
        }

        /** @var AutoDocTagOptions $options */
        $options = [];

        foreach ($optionsType->shape as $key => $optionType) {
            $optionType = $optionType->unwrapType($scope->config);

            if ($key === 'omit' || $key === 'only') {
                $options[$key] = $this->parseStringListOption($key, $optionType);

            } else if ($key === 'from') {
                $className = $this->parseFromOption($optionType);
                $options[$key] = $className;
                $scope = $scope->createChildScope($className);

            } else if ($key === 'with') {
                $options[$key] = $this->parseWithOption($optionType);

            } else if ($key === 'mode' || $key === 'as') {
                $options[$key] = $this->parseStringOption($key, $optionType);

            } else {
                throw new Exception('Unknown tag option: ' . $key);
            }
        }

        return new ParsedAutoDocTag($scope, $tagValue, $options);
    }

    /**
     * @return string[]
     */
    private function parseStringListOption(string $name, Type $type): array
    {
        if ($type instanceof StringType && $type->value) {
            return $type->getPossibleValues() ?? [];
        }

        throw new Exception('The value of `' . $name . '` tag must be a string or union of strings.');
    }

    /**
     * @return class-string
     */
    private function parseFromOption(Type $type): string
    {
        if ($type instanceof StringType && is_string($type->value)) {
            if (class_exists($type->value) || interface_exists($type->value) || trait_exists($type->value)) {
                return $type->value;
            }

            throw new Exception('The value of `from` tag is not a valid class name.');
        }

        if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
            return $type->className;
        }

        throw new Exception('The value of `from` tag must be a string or a class type identifier.');
    }

    /**
     * @return array<int|string, Type>
     */
    private function parseWithOption(Type $type): array
    {
        if ($type instanceof ObjectType && $type->properties) {
            return $type->properties;
        }

        if ($type instanceof ArrayType && $type->shape) {
            return $type->shape;
        }

        throw new Exception('The value of `with` tag must be an object or array shape.');
    }

    private function parseStringOption(string $name, Type $type): string
    {
        if ($type instanceof StringType && is_string($type->value)) {
            return $type->value;
        }

        throw new Exception('The value of `' . $name . '` tag must be a string.');
    }
}
