<?php

namespace App\Modules\Ingestion\Services;

class CodeChunker
{
    /**
     * Chunk a file into logical units based on its type.
     *
     * @param string $content  File content
     * @param string $filePath File path (used to determine type)
     * @param string $stack    App stack ('laravel', 'node')
     * @return array Array of chunks with code and metadata hints
     */
    public function chunk(string $content, string $filePath, string $stack): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return match ($extension) {
            'php'      => $this->chunkPhp($content, $filePath),
            'js', 'ts' => $this->chunkJavaScript($content, $filePath),
            'sql'      => $this->chunkSql($content, $filePath),
            default    => $this->chunkWholeFile($content, $filePath),
        };
    }

    // =========================================================================
    // PHP
    // =========================================================================

    /**
     * Chunk PHP files by type.
     * Handles classes, templates, route files, migrations, and config files.
     */
    private function chunkPhp(string $content, string $filePath): array
    {
        // Route files
        if (str_contains($filePath, 'routes/')) {
            return $this->chunkPhpRoutes($content, $filePath);
        }

        // Migration files — whole file is the logical unit
        if (str_contains($filePath, 'migrations/')) {
            return $this->chunkWholeFile($content, $filePath, 'migration');
        }

        // Config files
        if (str_contains($filePath, 'config/')) {
            $lineCount = substr_count($content, "\n");
            if ($lineCount < 100) {
                return $this->chunkWholeFile($content, $filePath, 'config');
            }
            return $this->chunkLargeConfig($content, $filePath);
        }

        // Mixed PHP/HTML template files
        if ($this->isMixedTemplate($content)) {
            return $this->chunkPhpTemplate($content, $filePath);
        }

        // Standard class files
        $codeType  = $this->inferPhpCodeType($filePath);
        $className = $this->extractPhpClassName($content);

        if ($className) {
            $methods = $this->extractPhpMethods($content);

            if (!empty($methods)) {
                $chunks = [];
                foreach ($methods as $method) {
                    $chunks[] = [
                        'code'        => $method['code'],
                        'class_name'  => $className,
                        'method_name' => $method['name'],
                        'code_type'   => $codeType,
                    ];
                }
                return $chunks;
            }

            // Class exists but no methods extracted — chunk whole class
            return [[
                'code'        => $content,
                'class_name'  => $className,
                'method_name' => null,
                'code_type'   => $codeType,
            ]];
        }

        // Not a class file
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => 'file',
        ]];
    }

    /**
     * Chunk PHP route files — one chunk per Route:: definition.
     */
    private function chunkPhpRoutes(string $content, string $filePath): array
    {
        $chunks = [];

        preg_match_all(
            '/Route::(get|post|put|patch|delete|any|match|resource|apiResource)\s*\([^;]+;/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $chunks[] = [
                'code'        => $match[0],
                'class_name'  => null,
                'method_name' => null,
                'code_type'   => 'route',
            ];
        }

        if (count($chunks) < 2) {
            return $this->chunkWholeFile($content, $filePath, 'route');
        }

        return $chunks;
    }

    /**
     * Detect mixed PHP/HTML template files.
     */
    private function isMixedTemplate(string $content): bool
    {
        return (bool) preg_match('/<html|<body|<table|<div|<!DOCTYPE/i', $content)
            && str_contains($content, '<?php');
    }

    /**
     * Chunk mixed PHP/HTML template files.
     * Extracts PHP logic blocks and JavaScript blocks as separate chunks.
     */
    private function chunkPhpTemplate(string $content, string $filePath): array
    {
        $chunks = [];

        // Extract PHP logic blocks
        preg_match_all('/<\?php([\s\S]*?)\?>/i', $content, $phpBlocks);
        if (!empty($phpBlocks[1])) {
            $phpLogic = implode("\n", array_map('trim', $phpBlocks[1]));
            if (strlen(trim($phpLogic)) > 30) {
                $chunks[] = [
                    'code'        => $phpLogic,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'template_logic',
                ];
            }
        }

        // Extract JavaScript blocks separately
        preg_match_all('/<script[^>]*>([\s\S]*?)<\/script>/i', $content, $jsBlocks);
        foreach ($jsBlocks[1] ?? [] as $jsBlock) {
            $jsBlock = trim($jsBlock);
            if (strlen($jsBlock) > 30) {
                $chunks[] = [
                    'code'        => $jsBlock,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'template_script',
                ];
            }
        }

        if (empty($chunks)) {
            return $this->chunkWholeFile($content, $filePath, 'template');
        }

        return $chunks;
    }

    /**
     * Infer PHP code type from file path.
     */
    private function inferPhpCodeType(string $filePath): string
    {
        if (str_contains($filePath, 'Services/'))     return 'service';
        if (str_contains($filePath, 'Controllers/'))  return 'controller';
        if (str_contains($filePath, 'Models/'))       return 'model';
        if (str_contains($filePath, 'Jobs/'))         return 'job';
        if (str_contains($filePath, 'Repositories/')) return 'repository';
        if (str_contains($filePath, 'Http/'))         return 'http';
        return 'class';
    }

    /**
     * Extract PHP class name from file content.
     */
    private function extractPhpClassName(string $content): ?string
    {
        if (preg_match('/class\s+(\w+)/', $content, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Extract all public/protected/private methods from a PHP class.
     */
    private function extractPhpMethods(string $content): array
    {
        $methods = [];

        $pattern = '/((?:\/\*\*[\s\S]*?\*\/\s*)?(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)(?:\s*:\s*[^{]+)?\s*\{)/';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $methods;
        }

        for ($i = 0; $i < count($matches[0]); $i++) {
            $methodStart = $matches[0][$i][1];
            $methodName  = $matches[2][$i][0];
            $methodCode  = $this->extractMethodBody($content, $methodStart);

            if ($methodCode) {
                $methods[] = [
                    'name' => $methodName,
                    'code' => $methodCode,
                ];
            }
        }

        return $methods;
    }

    // =========================================================================
    // Config
    // =========================================================================

    /**
     * Chunk large config files intelligently.
     *
     * Strategy order:
     *   1. Top-level assignment chunking (handles arrays + scalars, both patterns)
     *   2. $conf key prefix grouping (fallback for purely scalar files)
     *   3. Fixed line-count chunks with overlap (absolute last resort)
     */
    private function chunkLargeConfig(string $content, string $filePath): array
    {
        // Strategy 1 — chunk by top-level assignments
        $chunks = $this->chunkConfigByTopLevelAssignments($content);
        if (!empty($chunks)) {
            return $chunks;
        }

        // Strategy 2 — group scalar $conf keys by prefix
        if (str_contains($content, '$conf[')) {
            $chunks = $this->chunkConfByKeyPrefix($content);
            if (!empty($chunks)) {
                return $chunks;
            }
        }

        // Strategy 3 — fixed line-count fallback with overlap
        return $this->chunkByLineCount($content, 80, 10);
    }

    /**
     * Chunk config by top-level assignments.
     *
     * Handles all patterns:
     *   $conf['key'] = 'scalar';
     *   $conf['key'] = ['flat' => 'array'];
     *   $conf['key'] = ['nested' => ['deep' => 'value']];
     *   return ['group' => [...]];
     *
     * Core rule: Never split inside a value — only between top-level assignments.
     * Every chunk produced is a complete, valid PHP expression.
     */
    private function chunkConfigByTopLevelAssignments(string $content): array
    {
        $chunks            = [];
        $lines             = explode("\n", $content);
        $currentChunkLines = [];
        $bracketDepth      = 0;
        $inAssignment      = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Detect start of a new top-level assignment
            $isAssignmentStart = preg_match('/^\$conf\[\'[^\']+\'\]\s*=/', $trimmed)
                || preg_match('/^return\s*\[/', $trimmed)
                || ($bracketDepth === 0 && preg_match('/^\'[^\']+\'\s*=>/', $trimmed));

            // Save completed previous chunk before starting a new one
            if ($isAssignmentStart && $bracketDepth === 0 && !empty($currentChunkLines)) {
                $code = trim(implode("\n", $currentChunkLines));
                if (strlen($code) > 30) {
                    $chunks[] = $this->buildConfigChunk($code, $currentChunkLines);
                }
                $currentChunkLines = [];
                $inAssignment      = true;
            }

            $currentChunkLines[] = $line;

            // Track bracket depth to detect end of array values
            $bracketDepth += substr_count($line, '[') - substr_count($line, ']');
            $bracketDepth += substr_count($line, '{') - substr_count($line, '}');
            $bracketDepth  = max(0, $bracketDepth);

            // Assignment complete when depth returns to 0 and line ends with ;
            if ($bracketDepth === 0 && str_ends_with(rtrim($trimmed), ';') && $inAssignment) {
                $code          = trim(implode("\n", $currentChunkLines));
                $subLineCount  = count($currentChunkLines);

                if ($subLineCount > 60) {
                    // Large array value — split by sub-keys
                    $subChunks = $this->chunkLargeArrayValue($currentChunkLines);
                    $chunks    = array_merge($chunks, $subChunks);
                } else {
                    if (strlen($code) > 30) {
                        $chunks[] = $this->buildConfigChunk($code, $currentChunkLines);
                    }
                }

                $currentChunkLines = [];
                $inAssignment      = false;
            }
        }

        // Catch any remaining lines
        if (!empty($currentChunkLines)) {
            $code = trim(implode("\n", $currentChunkLines));
            if (strlen($code) > 30) {
                $chunks[] = $this->buildConfigChunk($code, $currentChunkLines);
            }
        }

        return $chunks;
    }

    /**
     * Build a config chunk with key name extracted as method_name metadata.
     */
    private function buildConfigChunk(string $code, array $lines): array
    {
        $keyName   = null;
        $firstLine = trim($lines[0] ?? '');

        if (preg_match('/\$conf\[\'([^\']+)\'/', $firstLine, $match)) {
            $keyName = $match[1];
        } elseif (preg_match('/\'([^\']+)\'\s*=>/', $firstLine, $match)) {
            $keyName = $match[1];
        }

        return [
            'code'        => $code,
            'class_name'  => null,
            'method_name' => $keyName,
            'code_type'   => 'config',
        ];
    }

    /**
     * Split a single oversized array value (> 60 lines) by its own sub-keys.
     * Keeps the assignment header on every sub-chunk for context.
     */
    private function chunkLargeArrayValue(array $lines): array
    {
        $chunks       = [];
        $currentLines = [];
        $depth        = 0;
        $firstLine    = true;
        $header       = $lines[0] ?? '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            $depth += substr_count($line, '[') - substr_count($line, ']');
            $depth += substr_count($line, '{') - substr_count($line, '}');
            $depth  = max(0, $depth);

            // At depth 1 (inside the outer array) each sub-key is a split point
            if ($depth === 1 && !$firstLine && preg_match('/^\'[^\']+\'\s*=>/', $trimmed)) {
                if (!empty($currentLines)) {
                    $code = trim($header . "\n" . implode("\n", $currentLines));
                    if (strlen($code) > 30) {
                        $chunks[] = $this->buildConfigChunk($code, $currentLines);
                    }
                    $currentLines = [];
                }
            }

            $currentLines[] = $line;
            $firstLine      = false;
        }

        // Catch remaining lines
        if (!empty($currentLines)) {
            $code = trim($header . "\n" . implode("\n", $currentLines));
            if (strlen($code) > 30) {
                $chunks[] = $this->buildConfigChunk($code, $currentLines);
            }
        }

        return empty($chunks)
            ? [$this->buildConfigChunk(implode("\n", $lines), $lines)]
            : $chunks;
    }

    /**
     * Group scalar $conf assignments by key prefix.
     * e.g. $conf['billing_tax'] and $conf['billing_currency'] → 'billing' chunk.
     */
    private function chunkConfByKeyPrefix(string $content): array
    {
        $lines  = explode("\n", $content);
        $groups = [];

        foreach ($lines as $line) {
            if (preg_match('/\$conf\[\'([^\']+)\'/', $line, $match)) {
                $key    = $match[1];
                $parts  = explode('_', $key);
                $prefix = $parts[0];
                $groups[$prefix][] = $line;
            } else {
                $lastPrefix = array_key_last($groups);
                if ($lastPrefix !== null) {
                    $groups[$lastPrefix][] = $line;
                }
            }
        }

        $chunks = [];
        foreach ($groups as $prefix => $groupLines) {
            $code = trim(implode("\n", $groupLines));
            if (strlen($code) > 30) {
                $chunks[] = [
                    'code'        => $code,
                    'class_name'  => null,
                    'method_name' => $prefix,
                    'code_type'   => 'config',
                ];
            }
        }

        return $chunks;
    }

    /**
     * Last resort: split by fixed line count with overlap.
     * Used when no grouping pattern is detectable.
     */
    private function chunkByLineCount(string $content, int $chunkLines = 80, int $overlap = 10): array
    {
        $lines  = explode("\n", $content);
        $total  = count($lines);
        $chunks = [];
        $step   = max(1, $chunkLines - $overlap);

        for ($i = 0; $i < $total; $i += $step) {
            $slice = array_slice($lines, $i, $chunkLines);
            $code  = trim(implode("\n", $slice));

            if (strlen($code) > 30) {
                $chunks[] = [
                    'code'        => $code,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'config',
                ];
            }
        }

        return empty($chunks)
            ? $this->chunkWholeFile($content, '', 'config')
            : $chunks;
    }

    // =========================================================================
    // JavaScript / TypeScript
    // =========================================================================

    /**
     * Chunk JavaScript/TypeScript files by function and method boundaries.
     * Handles named functions, arrow functions, class methods, and route files.
     */
    private function chunkJavaScript(string $content, string $filePath): array
    {
        $chunks = [];

        if (str_contains($filePath, 'routes/') || str_contains($filePath, 'router')) {
            return $this->chunkJsRoutes($content, $filePath);
        }

        $codeType  = $this->inferJsCodeType($filePath);
        $className = $this->extractJsClassName($content);

        $pattern = '/
            (?:\/\*[\s\S]*?\*\/\s*)?                                            # optional block comment
            (?:export\s+(?:default\s+)?)?                                       # optional export
            (?:
                (?:async\s+)?function\s+(\w+)\s*\([^)]*\)\s*\{                 # named function
                |
                (?:const|let)\s+(\w+)\s*=\s*(?:async\s*)?\([^)]*\)\s*=>\s*\{  # arrow function
                |
                (?:async\s+)?([a-zA-Z_$][\w$]*)\s*\([^)]*\)\s*\{              # class method
            )
        /x';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $this->chunkWholeFile($content, $filePath, $codeType);
        }

        foreach ($matches[0] as $i => $match) {
            $startPos = $match[1];

            $funcName = $matches[1][$i][0]
                     ?: $matches[2][$i][0]
                     ?: $matches[3][$i][0];

            // Skip control flow false positives
            if (in_array($funcName, ['if', 'for', 'while', 'switch', 'catch', 'else'])) {
                continue;
            }

            $methodCode = $this->extractMethodBody($content, $startPos);

            if ($methodCode && strlen(trim($methodCode)) > 30) {
                $chunks[] = [
                    'code'        => $methodCode,
                    'class_name'  => $className,
                    'method_name' => $funcName ?: null,
                    'code_type'   => $codeType,
                ];
            }
        }

        if (empty($chunks)) {
            return $this->chunkWholeFile($content, $filePath, $codeType);
        }

        return $chunks;
    }

    /**
     * Chunk JS/TS route files — one chunk per Express-style route definition.
     */
    private function chunkJsRoutes(string $content, string $filePath): array
    {
        $chunks = [];

        preg_match_all(
            '/(?:router|app)\.(get|post|put|patch|delete|use)\s*\([\s\S]*?(?=(?:router|app)\.|$)/m',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $code = trim($match[0]);
            if (strlen($code) > 20) {
                $chunks[] = [
                    'code'        => $code,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'route',
                ];
            }
        }

        if (count($chunks) < 2) {
            return $this->chunkWholeFile($content, $filePath, 'route');
        }

        return $chunks;
    }

    /**
     * Infer JS/TS code type from file path.
     */
    private function inferJsCodeType(string $filePath): string
    {
        if (str_contains($filePath, 'service'))    return 'service';
        if (str_contains($filePath, 'controller')) return 'controller';
        if (str_contains($filePath, 'model'))      return 'model';
        if (str_contains($filePath, 'middleware')) return 'middleware';
        if (str_contains($filePath, 'route'))      return 'route';
        if (str_contains($filePath, 'helper'))     return 'helper';
        if (str_contains($filePath, 'util'))       return 'utility';
        if (str_contains($filePath, 'repository')) return 'repository';
        return 'class';
    }

    /**
     * Extract JS/TS class name from file content (if present).
     */
    private function extractJsClassName(string $content): ?string
    {
        if (preg_match('/class\s+(\w+)/', $content, $match)) {
            return $match[1];
        }
        return null;
    }

    // =========================================================================
    // SQL
    // =========================================================================

    /**
     * Chunk SQL files — one chunk per statement (split by semicolons).
     */
    private function chunkSql(string $content, string $filePath): array
    {
        $chunks = [];

        $statements = preg_split('/;\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (strlen($statement) > 20) {
                $chunks[] = [
                    'code'        => $statement . ';',
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'query',
                ];
            }
        }

        if (empty($chunks)) {
            return $this->chunkWholeFile($content, $filePath, 'query');
        }

        return $chunks;
    }

    // =========================================================================
    // Shared Utilities
    // =========================================================================

    /**
     * Extract a method or function body by matching opening and closing braces.
     *
     * Note: Does not account for braces inside strings or comments.
     * Acceptable for MVP — replace with tree-sitter in v3 for full accuracy.
     */
    private function extractMethodBody(string $content, int $startPos): ?string
    {
        $braceCount = 0;
        $started    = false;
        $length     = strlen($content);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '{') {
                $braceCount++;
                $started = true;
            } elseif ($char === '}') {
                $braceCount--;
                if ($started && $braceCount === 0) {
                    return substr($content, $startPos, $i - $startPos + 1);
                }
            }
        }

        return null;
    }

    /**
     * Chunk a whole file as a single unit.
     * Used as fallback when no logical boundaries are found.
     */
    private function chunkWholeFile(string $content, string $filePath, string $codeType = 'file'): array
    {
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => $codeType,
        ]];
    }
}
