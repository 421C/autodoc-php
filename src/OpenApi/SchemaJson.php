<?php declare(strict_types=1);

namespace AutoDoc\OpenApi;

class SchemaJson
{
    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    public static function castPropertiesToObjects(array $schema): array
    {
        foreach ($schema as $keyword => $value) {
            if (! is_array($value) || in_array($keyword, ['examples', 'example', 'default', 'const', 'enum'], true)) {
                continue;
            }

            if ($keyword === 'properties') {
                $properties = array_map(self::castSubschemaProperties(...), $value);

                $schema[$keyword] = array_is_list($properties) ? (object) $properties : $properties;

            } else if (array_is_list($value)) {
                $schema[$keyword] = array_map(self::castSubschemaProperties(...), $value);

            } else {
                $schema[$keyword] = self::castPropertiesToObjects($value);
            }
        }

        return $schema;
    }


    private static function castSubschemaProperties(mixed $schema): mixed
    {
        return is_array($schema) ? self::castPropertiesToObjects($schema) : $schema;
    }
}
