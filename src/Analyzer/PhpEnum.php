<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

use AutoDoc\DataTypes\IntegerType;
use AutoDoc\DataTypes\StringType;
use AutoDoc\DataTypes\Type;
use AutoDoc\Workspace;
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
        if (isset(self::$cache[$this->phpClass->className])) {
            return clone self::$cache[$this->phpClass->className];
        }

        $enumConfig = $this->phpClass->scope->config->getEnumConfig($this->phpClass->className);
        $enumPhpDoc = $this->phpClass->getPhpDoc();

        $enumCaseNodeVisitor = new EnumCaseNodeVisitor($this->phpClass->scope);
        $this->phpClass->traverse($enumCaseNodeVisitor);

        $type = $enumCaseNodeVisitor->backingType === 'int'
            ? new IntegerType
            : new StringType;

        if ($enumConfig['show_allowed_values'] ?? true) {
            $type->setEnumValues(array_column($enumCaseNodeVisitor->enumCases, 'value'));
        }

        $removeDescriptions = $enumConfig['remove_description'] ?? false;

        $title = null;

        if ($enumPhpDoc) {
            [$title, $type->description] = $enumPhpDoc->getSummaryAndDescription();

            if ($removeDescriptions) {
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
                    if (! $removeDescriptions && $title) {
                        $typeForEnumComponent->addDescription($title, prepend: true);
                    }

                    $typeForEnumComponent->addDescription($this->generateDescriptionFromCases($enumCaseNodeVisitor));
                    $typeForEnumComponent->setEnumValues([]);
                }

                Workspace::$schemaComponents[$enumName] = $typeForEnumComponent;
            }

            if ($enumConfig['create_links'] ?? false) {
                $type->addDescription("[$title](#/schemas/" . urlencode($enumName) . ')', prepend: true);
            }
        }

        self::$cache[$this->phpClass->className] = clone $type;

        return $type;
    }


    private function generateDescriptionFromCases(EnumCaseNodeVisitor $enumCaseNodeVisitor): string
    {
        if (! $enumCaseNodeVisitor->enumCases) {
            return '';
        }

        $rows = [];

        $darkMode = ($this->phpClass->scope->config->data['ui']['theme'] ?? 'light') === 'dark';

        foreach ($enumCaseNodeVisitor->enumCases as $case) {
            $rows[] = '<tr>'
                . '<td style="padding: 8px; font-family: monospace; color:' . ($darkMode ? '#eee' : '#111') . '">'
                    . ($case['description'] ?? $case['name'])
                . '</td>'
                . '<td style="padding: 8px; font-family: monospace; color:' . ($darkMode ? '#ccc' : '#333') . '">'
                    . $case['value']
                . '</td>'
            . '</tr>';
        }

        return '<table style="width:100%; min-width:100%; font-size:14px; color:#0f172a; margin:16px 0; border-collapse:collapse;">'
            . '<thead>'
                . '<tr>'
                    . '<th style="text-align: left; padding: 8px; font-weight: bold;">Name</th>'
                    . '<th style="text-align: left; padding: 8px; font-weight: bold;">Value</th>'
                . '</tr>'
            . '</thead>'
            . '<tbody>'
                . implode('', $rows)
            . '</tbody>'
        . '</table>';
    }

    private function escapeMarkdown(string $text): string
    {
        return str_replace(
            ['\\', '-', '#', '*', '+', '`', '.', '[', ']', '(', ')', '!', '&', '<', '>', '_', '{', '}', '|'],
            ['\\\\', '\-', '\#', '\*', '\+', '\`', '\.', '\[', '\]', '\(', '\)', '\!', '\&', '\<', '\>', '\_', '\{', '\}', '\|'],
            $text,
        );
    }

    /**
     * @var array<class-string, Type>
     */
    private static array $cache = [];
}
