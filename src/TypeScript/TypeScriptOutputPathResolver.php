<?php declare(strict_types=1);

namespace AutoDoc\TypeScript;

use Exception;

class TypeScriptOutputPathResolver
{
    /**
     * @param iterable<string, string> $pathPrefixes
     */
    public function resolve(string $filePath, iterable $pathPrefixes): string
    {
        foreach ($pathPrefixes as $prefix => $basePath) {
            if (str_starts_with($filePath, $prefix)) {
                return $basePath . substr($filePath, strlen($prefix));
            }
        }

        throw new Exception('No matching path prefix found for path "' . $filePath . '". Check your path_prefixes configuration.');
    }
}
