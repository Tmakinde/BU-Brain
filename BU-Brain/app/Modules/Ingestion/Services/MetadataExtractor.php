<?php

namespace App\Modules\Ingestion\Services;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Error as PhpParserError;
use App\Modules\Ingestion\Visitors\TableReferenceVisitor;
use PhpParser\PhpVersion;

/**
 * Extracts metadata from a code chunk.
 *
 * The chunker already provides class_name, method_name, and code_type.
 * This class fills any gaps via inference and extracts tables_referenced
 * using PHP-Parser AST traversal — the real work this class exists to do.
 *
 * PHP files: parsed via PHP-Parser for accurate table extraction.
 * Non-PHP files (SQL): fallback regex on raw SQL strings.
 */
class MetadataExtractor
{
    private \PhpParser\Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.1'));
    }

    /**
     * Extract metadata from a code chunk.
     *
     * @param string $code      The cleaned code
     * @param string $filePath  The file path
     * @param array  $chunkMeta Metadata already provided by CodeChunker
     * @return array Complete metadata ready for storage
     */
    public function extract(string $code, string $filePath, array $chunkMeta): array
    {
        return [
            'file_path'         => $filePath,
            'class_name'        => $chunkMeta['class_name']  ?? $this->inferClassName($code, $filePath),
            'method_name'       => $chunkMeta['method_name'] ?? $this->inferMethodName($code),
            'code_type'         => $chunkMeta['code_type']   ?? $this->inferCodeType($filePath),
            'tables_referenced' => $this->extractTableReferences($code, $filePath),
        ];
    }

    // =========================================================================
    // Table reference extraction
    // =========================================================================

    /**
     * Extract all database table references from a code chunk.
     *
     * PHP files: parsed into an AST, TableReferenceVisitor walks the tree.
     * SQL files: regex on raw SQL strings (no PHP to parse).
     */
    private function extractTableReferences(string $code, string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        // SQL files — no PHP to parse, scan the raw SQL string directly
        if ($extension === 'sql') {
            return $this->extractTablesFromRawSql($code);
        }

        // PHP files — parse into AST and walk with visitor
        if (in_array($extension, ['php'])) {
            return $this->extractTablesFromPhp($code, $filePath);
        }

        // Everything else — scan for raw SQL patterns in string literals
        return $this->extractTablesFromRawSql($code);
    }

    /**
     * Parse PHP code into an AST and extract table references via visitor.
     */
    private function extractTablesFromPhp(string $code, string $filePath): array
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (PhpParserError $e) {
            // Parse failure — fall back to raw SQL scan
            logger()->warning("MetadataExtractor: parse failed for {$filePath}: {$e->getMessage()}");
            return $this->extractTablesFromRawSql($code);
        }

        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser();
        $visitor   = new TableReferenceVisitor();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getTables();
    }

    /**
     * Extract table names from raw SQL strings using regex.
     * Used for .sql files and as a fallback for unparseable PHP.
     */
    private function extractTablesFromRawSql(string $code): array
    {
        $tables = [];

        preg_match_all(
            '/\b(?:FROM|JOIN|INTO|UPDATE)\s+[`"\']?(\w+)[`"\']?/i',
            $code,
            $matches
        );

        $tables = array_merge($tables, $matches[1]);

        $ignore = [
            'select', 'where', 'set', 'null', 'dual', 'table',
            'row', 'result', 'data', 'query', 'values', 'index',
            'information_schema', 'performance_schema', 'sys', 'mysql',
        ];

        $tables = array_unique($tables);
        $tables = array_filter(
            $tables,
            fn($t) => !in_array(strtolower($t), $ignore) && strlen($t) > 2
        );

        return array_values($tables);
    }

    // =========================================================================
    // Inference fallbacks
    // These only fire when CodeChunker didn't already provide the value.
    // =========================================================================

    private function inferClassName(string $code, string $filePath): ?string
    {
        if (preg_match('/class\s+(\w+)/', $code, $match)) {
            return $match[1];
        }

        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        if (preg_match('/^[A-Z][a-zA-Z0-9]+$/', $filename)) {
            return $filename;
        }

        return null;
    }

    private function inferMethodName(string $code): ?string
    {
        if (preg_match('/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)/', $code, $match)) {
            return $match[1];
        }

        if (preg_match('/function\s+(\w+)/', $code, $match)) {
            return $match[1];
        }

        return null;
    }

    private function inferCodeType(string $filePath): string
    {
        if (str_contains($filePath, 'routes/'))                                    return 'route';
        if (str_contains($filePath, 'migrations/'))                                return 'migration';
        if (str_contains($filePath, 'config/'))                                    return 'config';
        if (str_contains($filePath, 'Controller'))                                 return 'controller';
        if (str_contains($filePath, 'Model') || str_contains($filePath, 'Models/')) return 'model';
        if (str_contains($filePath, 'Service'))                                    return 'service';
        if (str_ends_with($filePath, '.sql'))                                      return 'query';
        return 'file';
    }
}