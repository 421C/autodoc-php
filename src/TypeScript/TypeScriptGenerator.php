<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\DocBlock\PhpDoc;
use AutoDoc\DataTypes\ArrayType;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use Closure;
use Exception;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use UnitEnum;

class TypeScriptGenerator
{
    public function __construct(
        private readonly TypeConverter $typeConverter = new TypeConverter,
        private readonly TypeScriptOutputPathResolver $outputPathResolver = new TypeScriptOutputPathResolver,
        private readonly TypeScriptRouteTagResolver $routeTagResolver = new TypeScriptRouteTagResolver,
    ) {}

    /**
     * @var array<string, TypeScriptExportFile>
     */
    private array $exportFiles = [];

    public function generateTypeScriptDeclaration(AutoDocTag $tag): string
    {
        if (empty($tag->value)) {
            throw new Exception('Missing argument after @autodoc tag');
        }

        if ($this->routeTagResolver->supports($tag->value)) {
            return $this->declarationFromRouteTag($tag);
        }

        return $this->declarationFromTypeExpression($tag);
    }


    private function declarationFromRouteTag(AutoDocTag $tag): string
    {
        try {
            $resolvedRoute = $this->routeTagResolver->resolve($tag->value, $tag->scope);

        } catch (Exception $exception) {
            $tag->throwError($exception->getMessage());
        }

        $type = $resolvedRoute->type->unwrapType($tag->scope->config);
        $exportedClassName = ($type instanceof ObjectType || $type instanceof ArrayType) ? $type->className : null;

        if ($type instanceof ObjectType && $type->typeToDisplay) {
            $type = $type->typeToDisplay->unwrapType($tag->scope->config);
        }

        $lastPartOfUri = preg_replace('/[^a-zA-Z]/', ' ', basename($resolvedRoute->route->uri));
        $name = $tag->getExistingStructureName() ?? $this->toPascalCase($lastPartOfUri . $resolvedRoute->declarationSuffix);

        return $this->renderDeclarationLine($tag, $name, $type, $exportedClassName);
    }


    private function declarationFromTypeExpression(AutoDocTag $tag): string
    {
        $phpDoc = new PhpDoc(
            docComment: '/**  */',
            scope: $tag->scope,
        );

        $type = $phpDoc->createUnresolvedType($phpDoc->createTypeNode($tag->value))->unwrapType($tag->scope->config);

        $exportedClassName = ($type instanceof ObjectType || $type instanceof ArrayType) ? $type->className : null;

        $name = $tag->getExistingStructureName();

        if ($name === null) {
            if ($type instanceof ObjectType && $type->className) {
                $name = PhpClass::basename($type->className);

            } else {
                $name = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tag->value) ? $tag->value : 'UnnamedType';
            }
        }

        if ($type instanceof ObjectType && $type->className && enum_exists($type->className)) {
            return $this->renderEnumDeclarationLine($tag, $name, $type->className);
        }

        if (($type instanceof ObjectType || $type instanceof ArrayType) && $type->className) {
            $phpClass = new PhpClass($type->className, $tag->scope);

            $type = $tag->scope->extensions->handleTypeScriptExportExtensions($phpClass, $type);
        }

        if ($type instanceof ObjectType && $type->typeToDisplay) {
            if ($type->typeToDisplay instanceof ObjectType
                || $type->typeToDisplay instanceof ArrayType
            ) {
                $type = $type->typeToDisplay;
            }
        }

        if ($type instanceof ObjectType && $type->typeToDisplay) {
            // The root declaration shows the real structure; clone so the shared cached type keeps its display type.
            $type = clone $type;
            $type->typeToDisplay = null;
        }

        return $this->renderDeclarationLine($tag, $name, $type, $exportedClassName);
    }


    /**
     * Renders the inline declaration for a tag; in separate-file mode also
     * registers the exported declaration and points the inline one at it.
     */
    private function renderDeclarationLine(AutoDocTag $tag, string $name, Type $type, ?string $exportedClassName): string
    {
        $baseIndent = $tag->getDeclarationIndent();
        $outputFilePath = $tag->getConfig('save_types_in_single_file');

        $structureType = ($this->isObjectOrArrayShape($type) && ! $outputFilePath)
            ? $tag->getExistingStructureType() ?? 'type'
            : 'type';

        $declarationHeader = $this->generateDeclarationHeader($tag->addExportKeyword, $name, $structureType);

        if ($outputFilePath) {
            $exportName = $tag->options['as'] ?? $name;

            $this->addFileDeclaration(
                $tag,
                $exportName,
                $outputFilePath,
                fn (array $namedTypes): string => $baseIndent
                    . $this->generateDeclarationHeader(true, $exportName, $structureType)
                    . $this->renderType($type, $tag, $baseIndent, $namedTypes),
                $exportedClassName,
            );

            return $baseIndent . $declarationHeader . $this->generateImportStatement($tag, $exportName, $outputFilePath);
        }

        return $baseIndent . $declarationHeader . $this->renderType($type, $tag, $baseIndent);
    }


    /**
     * @param class-string<UnitEnum> $enumClassName
     */
    private function renderEnumDeclarationLine(AutoDocTag $tag, string $name, string $enumClassName): string
    {
        $baseIndent = $tag->getDeclarationIndent();
        $outputFilePath = $tag->getConfig('save_types_in_single_file');
        $typeDefinition = $this->renderEnumCases($tag, $enumClassName, $baseIndent);

        if ($outputFilePath) {
            $exportName = $tag->options['as'] ?? $name;

            $this->addFileDeclaration(
                $tag,
                $exportName,
                $outputFilePath,
                static fn (): string => $baseIndent . 'export enum ' . $exportName . ' ' . $typeDefinition,
                $enumClassName,
            );

            return $baseIndent
                . $this->generateDeclarationHeader($tag->addExportKeyword, $name, 'type')
                . $this->generateImportStatement($tag, $exportName, $outputFilePath);
        }

        return $baseIndent . ($tag->addExportKeyword ? 'export ' : '') . "enum $name " . $typeDefinition;
    }


    /**
     * @param class-string<UnitEnum> $enumClassName
     */
    private function renderEnumCases(AutoDocTag $tag, string $enumClassName, string $baseIndent): string
    {
        $indent = $tag->getConfig('indent');
        $reflectionEnum = new ReflectionEnum($enumClassName);
        $enumCaseDefinitions = [];

        foreach ($reflectionEnum->getCases() as $enumCase) {
            if (isset($tag->options['only']) && ! in_array($enumCase->name, $tag->options['only'])) {
                continue;
            }

            if (isset($tag->options['omit']) && in_array($enumCase->name, $tag->options['omit'])) {
                continue;
            }

            $value = $enumCase instanceof ReflectionEnumBackedCase ? $enumCase->getBackingValue() : $enumCase->name;

            if (is_string($value)) {
                $value = $this->typeConverter->toTsString($value, $tag->getConfig('string_quote'));
            }

            $enumCaseDefinitions[] = "{$baseIndent}{$indent}{$enumCase->name} = $value,";
        }

        return $enumCaseDefinitions
            ? '{' . "\n" . implode("\n", $enumCaseDefinitions) . "\n" . $baseIndent . '}'
            : '{}';
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


    private function generateDeclarationHeader(bool $export, string $name, string $structureType): string
    {
        return ($export ? 'export ' : '')
            . $structureType . ' '
            . $name . ' '
            . ($structureType === 'type' ? '= ' : '');
    }

    /**
     * @param array<string, string> $namedTypes
     */
    private function renderType(Type $type, AutoDocTag $tag, string $baseIndent, array $namedTypes = []): string
    {
        return $this->typeConverter->convertToTypeScriptType(
            $type,
            new TypeScriptRenderContext(
                scope: $tag->scope,
                config: $tag->getConfig(),
                baseIndent: $baseIndent,
                isRootLevel: true,
                namedTypes: $namedTypes,
                rootOptions: $tag->options,
            ),
        );
    }

    /**
     * @param Closure(array<string, string>): string $generateDefinition
     */
    private function addFileDeclaration(
        AutoDocTag $tag,
        string $name,
        string $filePath,
        Closure $generateDefinition,
        ?string $className,
    ): void {
        try {
            $fullPath = $this->outputPathResolver->resolve($filePath, $tag->getConfig('path_prefixes'));
            $this->exportFiles[$fullPath] ??= new TypeScriptExportFile($fullPath);
            $this->exportFiles[$fullPath]->add(new TypeScriptDeclaration($name, $className, $generateDefinition));

        } catch (Exception $exception) {
            $tag->throwError($exception->getMessage());
        }
    }

    private function generateImportStatement(AutoDocTag $tag, string $exportName, string $filePath): string
    {
        return 'import(' . $this->typeConverter->toTsString($filePath, $tag->getConfig('string_quote')) . ').' . $exportName;
    }

    public function overwriteGeneratedFiles(): void
    {
        foreach ($this->exportFiles as $file) {
            $file->write();
        }
    }
}
