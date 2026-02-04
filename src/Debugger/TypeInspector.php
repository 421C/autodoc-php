<?php declare(strict_types=1);

namespace AutoDoc\Debugger;

use AutoDoc\Analyzer\PhpClass;
use AutoDoc\Analyzer\PhpVariable;
use AutoDoc\Config;
use AutoDoc\DataTypes\Type;
use PhpParser\Node;
use ReflectionClass;
use ReflectionProperty;

class TypeInspector
{
    /**
     * @var array<string, string>
     */
    private array $colors = [
        'reset' => "\033[0m",
        'string' => "\033[32m",
        'number' => "\033[36m",
        'variable' => "\033[31m",
        'bool' => "\033[35m",
        'muted' => "\033[90m",
        'type' => "\033[34m",
        'special' => "\033[33m",
    ];

    /**
     * @var array<int, true>
     */
    private array $dumpedObjectIds = [];

    private int $maxLevel = 100;

    public function __invoke(mixed $value, int $maxLevel = 100): string
    {
        $this->maxLevel = $maxLevel;

        return $this->dump($value) . "\n";
    }

    private function dump(mixed $value, int $level = 0): string
    {
        return match (true) {
            is_string($value) => $this->dumpString($value),
            is_int($value), is_float($value) => $this->colors['number'] . (string) $value . $this->colors['reset'],
            is_bool($value) => $this->colors['bool'] . ($value ? 'true' : 'false') . $this->colors['reset'],
            $value === null => $this->colors['muted'] . 'null' . $this->colors['reset'],
            is_array($value) => $this->dumpArray($value, $level),
            is_object($value) => $this->dumpObject($value, $level),
            default => gettype($value),
        };
    }

    private function dumpString(string $string): string
    {
        $string = str_replace("\e", $this->colors['special'] . '\\e' . $this->colors['string'], $string);
        $string = str_replace("\n", $this->colors['special'] . '\\n' . $this->colors['string'], $string);
        $string = str_replace("\r", $this->colors['special'] . '\\r' . $this->colors['string'], $string);
        $string = str_replace("\t", $this->colors['special'] . '\\t' . $this->colors['string'], $string);
        $string = str_replace("\f", $this->colors['special'] . '\\f' . $this->colors['string'], $string);
        $string = str_replace("\v", $this->colors['special'] . '\\v' . $this->colors['string'], $string);

        return $this->colors['string'] . '\'' . $string . '\'' . $this->colors['reset'];
    }

    /**
     * @param array<mixed> $array
     */
    private function dumpArray(array $array, int $level): string
    {
        $indent = str_repeat('    ', $level);

        if (empty($array)) {
            return '[]';
        }

        if ($level > $this->maxLevel) {
            return '[ ... ]';
        }

        $out = $this->colors['muted'] . '(' . count($array) . ')' . $this->colors['reset'] . " [\n";

        foreach ($array as $key => $value) {
            $out .= $indent . '    '
                . $this->dump($key)
                . ' => '
                . $this->dump($value, $level + 1)
                . ",\n";
        }

        return $out . $indent . ']';
    }

    private function dumpObject(object $object, int $level): string
    {
        $indent = str_repeat('    ', $level);
        $properties = $this->getProperties($object);

        $typeName = $object::class;
        $objectId = spl_object_id($object);

        if ($object instanceof Type) {
            $typeName = PhpClass::basename($object::class);

        } else if ($typeName === 'stdClass') {
            $typeName = 'object';

        } else if ($object instanceof Node && $level > 1) {
            $properties = array_filter($properties, fn (ReflectionProperty $property) => $property->isPublic());

        } else if ($object instanceof Config && $level > 0) {
            $properties = [];

        } else if ($object instanceof PhpVariable) {
            return $this->colors['type'] . 'PhpVariable' . $this->colors['muted'] . ' #' . $objectId . $this->colors['variable'] . ' $' . $object->name . $this->colors['reset'] . ' mutations: ' . $this->dumpArray($object->mutations, $level);
        }

        $out = $this->colors['type'] . $typeName . $this->colors['muted'] . ' #' . $objectId . $this->colors['reset'];

        if (empty($properties)) {
            return $out;
        }

        if (isset($this->dumpedObjectIds[$objectId]) || $level > $this->maxLevel) {
            return $out . ' { ... }';
        }

        $this->dumpedObjectIds[$objectId] = true;

        $out .= " {\n";

        foreach ($properties as $property) {
            $value = $property->getValue($object);

            $out .= $indent . '    '
                . $this->propertyPrefix($property)
                . $property->getName()
                . ': '
                . $this->dump($value, $level + 1)
                . "\n";
        }

        return $out . $indent . '}';
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function getProperties(object $type): array
    {
        $reflection = new ReflectionClass($type);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);

            if ($property->isInitialized($type)) {
                $value = $property->getValue($type);

                if ($value === null || $value === [] || $value === false) {
                    continue;
                }

                $properties[] = $property;
            }
        }

        return $properties;
    }

    private function propertyPrefix(ReflectionProperty $property): string
    {
        if ($property->isPrivate()) {
            return $this->colors['muted'] . '-' . $this->colors['reset'];
        }

        if ($property->isProtected()) {
            return $this->colors['muted'] . '#' . $this->colors['reset'];
        }

        return '';
    }
}
