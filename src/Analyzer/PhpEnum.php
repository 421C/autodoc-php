<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Workspace;
use ReflectionEnum;
use UnitEnum;

/**
 * @template TEnum of UnitEnum
 */
class PhpEnum
{
    public function __construct(
        /**
         * @var PhpClass<TEnum>
         */
        private PhpClass $phpClass,
        public ?string $summary = null,
        public ?string $description = null,
    ) {}


    public function resolveType(): Type
    {
        $enumReflection = new ReflectionEnum($this->phpClass->className);

        $enumConfig = $this->phpClass->scope->config->getEnumConfig($this->phpClass->className);
        $enumPhpDoc = $this->phpClass->getPhpDoc();

        $reflectionType = $enumReflection->getBackingType();

        if ($reflectionType) {
            $type = Type::resolveFromReflection($reflectionType);

            if ($enumConfig['show_allowed_values'] ?? true) {
                $caseValues = array_column(array_map(fn ($case) => $case->getValue(), $enumReflection->getCases()), 'value');

                $type->setEnumValues($caseValues);
            }

        } else {
            $type = new StringType;

            if ($enumConfig['show_allowed_values'] ?? true) {
                $caseNames = array_column($enumReflection->getCases(), 'name');

                $type->setEnumValues($caseNames);
            }
        }

        $title = null;

        if ($enumPhpDoc) {
            [$title, $type->description] = $enumPhpDoc->getSummaryAndDescription();

            if ($enumConfig['remove_description'] ?? false) {
                $type->description = null;
            }
        }

        $addSchemaComponent = ($enumConfig['autodetect_components'] ?? false)
            || isset($this->phpClass->scope->config->getSchemasConfig()[$this->phpClass->className]);

        if ($addSchemaComponent) {
            if ($enumConfig['remove_namespace'] ?? false) {
                $enumName = PhpClass::basename($this->phpClass->className);

            } else {
                $enumName = $this->phpClass->className;
            }

            $title = $title ? $this->escapeMarkdown($title) : $enumName;

            if (! isset(Workspace::$schemaComponents[$enumName])) {
                $typeForEnumComponent = clone $type;

                if ($enumConfig['generate_description_from_cases'] ?? false) {
                    $typeForEnumComponent->addDescription($this->generateDescriptionFromCases());
                    $typeForEnumComponent->setEnumValues([]);
                }

                Workspace::$schemaComponents[$enumName] = $typeForEnumComponent;
            }

            if ($enumConfig['create_links'] ?? false) {
                $type->addDescription("[$title](#/schemas/" . urlencode($enumName) . ')', prepend: true);
            }
        }

        return $type;
    }


    private function generateDescriptionFromCases(): string
    {
        $enumFileName = $this->phpClass->getReflection()->getFileName();

        if (! $enumFileName) {
            return '';
        }

        $enumCaseNodeVisitor = new EnumCaseNodeVisitor($this->phpClass->scope);

        $this->phpClass->traverse($enumCaseNodeVisitor);

        if (! $enumCaseNodeVisitor->caseDescriptions) {
            return '';
        }

        $enumCaseHtmlDescriptions = [];

        foreach ($enumCaseNodeVisitor->caseDescriptions as $caseValue => $caseDescription) {
            $enumCaseHtmlDescriptions[] = '<span class="sl-bg-canvas-tint sl-rounded sl-border" style="text-align:center;min-width:42px;height:18px;display:inline-block;margin-right:6px;margin-bottom:6px;">' . $caseValue . '</span>' . trim($caseDescription);
        }

        return '<br><pre>' . implode('<br>', $enumCaseHtmlDescriptions) . '</pre><br>';
    }

    private function escapeMarkdown(string $text): string
    {
        return str_replace(
            ['\\', '-', '#', '*', '+', '`', '.', '[', ']', '(', ')', '!', '&', '<', '>', '_', '{', '}', '|'],
            ['\\\\', '\-', '\#', '\*', '\+', '\`', '\.', '\[', '\]', '\(', '\)', '\!', '\&', '\<', '\>', '\_', '\{', '\}', '\|'],
            $text,
        );
    }
}
