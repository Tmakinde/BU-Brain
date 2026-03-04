<?php

namespace App\Modules\Ingestion\Services;

class MetadataExtractor
{
    /**
     * Extract metadata from a code chunk.
     *
     * @param string $code The cleaned code
     * @param string $filePath The file path
     * @param array $chunkMeta Metadata from chunker (class_name, method_name, code_type)
     * @return array Complete metadata for the chunk
     */
    public function extract(string $code, string $filePath, array $chunkMeta): array
    {
        return [
            'file_path' => $filePath,
            'class_name' => $chunkMeta['class_name'] ?? $this->inferClassName($code, $filePath),
            'method_name' => $chunkMeta['method_name'] ?? $this->inferMethodName($code),
            'code_type' => $chunkMeta['code_type'] ?? $this->inferCodeType($filePath),
            'tables_referenced' => $this->extractTableReferences($code),
        ];
    }

    /**
     * Infer class name from code if not already provided.
     */
    private function inferClassName(string $code, string $filePath): ?string
    {
        // Try to extract from PHP class definition
        if (preg_match('/class\s+(\w+)/', $code, $match)) {
            return $match[1];
        }

        // Try to infer from filename
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        // Check if it looks like a class name (PascalCase)
        if (preg_match('/^[A-Z][a-zA-Z0-9]+$/', $filename)) {
            return $filename;
        }

        return null;
    }

    /**
     * Infer method name from code if not already provided.
     */
    private function inferMethodName(string $code): ?string
    {
        // Try PHP method
        if (preg_match('/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)/', $code, $match)) {
            return $match[1];
        }

        // Try JS/TS function
        if (preg_match('/function\s+(\w+)/', $code, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Infer code type from file path.
     */
    private function inferCodeType(string $filePath): string
    {
        if (str_contains($filePath, 'routes/')) {
            return 'route';
        }

        if (str_contains($filePath, 'migrations/')) {
            return 'migration';
        }

        if (str_contains($filePath, 'config/')) {
            return 'config';
        }

        if (str_contains($filePath, 'Controller')) {
            return 'controller';
        }

        if (str_contains($filePath, 'Model') || str_contains($filePath, 'Models/')) {
            return 'model';
        }

        if (str_contains($filePath, 'Service')) {
            return 'service';
        }

        if (str_ends_with($filePath, '.sql')) {
            return 'query';
        }

        return 'file';
    }

    /**
     * Extract database table references from code.
     */
    private function extractTableReferences(string $code): array
    {
        $tables = [];

        // Laravel table references
        // Schema::create('table_name', ...)
        preg_match_all("/Schema::\w+\s*\(\s*['\"](\w+)['\"]/", $code, $schemaMatches);
        $tables = array_merge($tables, $schemaMatches[1]);

        // DB::table('table_name')
        preg_match_all("/DB::table\s*\(\s*['\"](\w+)['\"]/", $code, $dbMatches);
        $tables = array_merge($tables, $dbMatches[1]);

        // Model::from('table_name') or ->from('table_name')
        preg_match_all("/->from\s*\(\s*['\"](\w+)['\"]/", $code, $fromMatches);
        $tables = array_merge($tables, $fromMatches[1]);

        // $table->... in migrations (infer table from Schema::create context)
        // protected $table = 'table_name' in models
        preg_match_all("/protected\s+\\\$table\s*=\s*['\"](\w+)['\"]/", $code, $tableMatches);
        $tables = array_merge($tables, $tableMatches[1]);

        // SQL FROM/JOIN/INTO/UPDATE clauses
        preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+[`"\']?(\w+)[`"\']?/i', $code, $sqlMatches);
        $tables = array_merge($tables, $sqlMatches[1]);

        // Remove duplicates and common false positives
        $tables = array_unique($tables);
        $tables = array_filter($tables, function ($table) {
            // Filter out common false positives
            $ignore = ['function', 'class', 'public', 'private', 'protected', 'static', 'return'];
            return !in_array(strtolower($table), $ignore);
        });

        return array_values($tables);
    }
}
