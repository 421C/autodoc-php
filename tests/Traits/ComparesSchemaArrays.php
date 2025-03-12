<?php declare(strict_types=1);

namespace AutoDoc\Tests\Traits;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-require-extends TestCase
 */
trait ComparesSchemaArrays
{
    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    protected function assertSchemaArraysMatch(array $expected, array $actual, string $uri, string $method): void
    {
        $this->sortArrayRecursively($expected);
        $this->sortArrayRecursively($actual);

        $differences = $this->findArrayDifferences($expected, $actual);

        try {
            $this->assertEmpty($differences);

        } catch (AssertionFailedError) {
            $this->fail(
                "Schema differences found:\n\n" . strtoupper($method) . " $uri\n\n"
                . "Expected:\n    " . $this->valueToPhpString($expected) . "\n\n"
                . "Actual:\n    " . $this->valueToPhpString($actual) . "\n\n"
                . ' -> ' . implode("\n\n -> ", $differences)
            );
        }
    }

    /**
     * @param array<mixed> &$array
     */
    private function sortArrayRecursively(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortArrayRecursively($value);
            }
        }

        if (array_values($array) !== $array) {
            ksort($array);
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     *
     * @return string[]
     */
    private function findArrayDifferences(array $expected, array $actual, string $prefix = ''): array
    {
        $diff = [];

        foreach ($expected as $key => $value) {
            $dotKey = ltrim($prefix . '.' . $key, '.');

            if (array_key_exists($key, $actual)) {
                if (is_array($value) && is_array($actual[$key])) {
                    $nestedDiff = $this->findArrayDifferences($value, $actual[$key], $dotKey);

                    if (!empty($nestedDiff)) {
                        $diff = array_merge($diff, $nestedDiff);
                    }

                } else if ($value !== $actual[$key]) {
                    $diff[] = $dotKey . ":\n        expected: " . $this->valueToPhpString($value) . ",\n        actual: " . $this->valueToPhpString($actual[$key]);
                }

            } else {
                $diff[] = $dotKey . ": key is missing\n        expected: " . $this->valueToPhpString($value, 1);
            }
        }

        foreach ($actual as $key => $value) {
            $dotKey = $prefix === '' ? $key : $prefix . '.' . $key;

            if (! array_key_exists($key, $expected)) {
                $diff[] = $dotKey . ": unexpected key\n        value: " . $this->valueToPhpString($value, 1);
            }
        }

        return $diff;
    }

    private function valueToPhpString(mixed $value, int $indentLevel = 0): string
    {
        if (is_array($value)) {
            if (count($value) === 0) {
                return '[]';
            }

            return $this->arrayToPhpString($value, $indentLevel + 1);

        } else if (is_string($value)) {
            return "'" . addslashes($value) . "'";

        } else if (is_bool($value)) {
            return $value ? 'true' : 'false';

        } else if ($value === null) {
            return 'null';

        } else if (is_object($value)) {
            return '(' . $value::class . ') ' . $this->arrayToPhpString((array) $value, $indentLevel + 1);

        } else if (is_int($value) || is_float($value)) {
            return (string) $value;

        } else {
            return var_export($value, true);
        }
    }

    /**
     * @param array<mixed> $array
     */
    private function arrayToPhpString(array $array, int $indentLevel = 0): string
    {
        $indent = str_repeat('    ', $indentLevel);
        $result = "[\n";

        $isList = array_is_list($array);

        foreach ($array as $key => $value) {
            $result .= $indent . '    ';

            if (! $isList) {
                if (is_string($key)) {
                    $result .= "'" . addslashes($key) . "'";

                } else {
                    $result .= $key;
                }

                $result .= ' => ';
            }

            $result .= $this->valueToPhpString($value, $indentLevel) . ",\n";
        }

        $result .= $indent . ']';

        return $result;
    }
}
