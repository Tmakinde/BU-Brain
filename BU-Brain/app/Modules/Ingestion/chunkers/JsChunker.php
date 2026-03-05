<?php

namespace App\Modules\Ingestion\Chunkers;

/**
 * Chunks JavaScript and TypeScript files into logical units.
 *
 * Extracts named functions, arrow functions, class methods, and route definitions
 * using regex + brace-matching. This is good enough for MVP.
 *
 * Note: tree-sitter will replace this in v3 for full AST accuracy,
 * the same way PHP-Parser replaced regex for PHP files in this version.
 */
class JsChunker
{
    public function chunk(string $content, string $filePath): array
    {
        $codeType = $this->inferCodeType($filePath);

        if (str_contains($filePath, 'routes/') || str_contains($filePath, 'router')) {
            return $this->chunkRouteFile($content, $codeType);
        }

        return $this->chunkByFunctions($content, $codeType);
    }

    // =========================================================================
    // Route files
    // =========================================================================

    private function chunkRouteFile(string $content, string $codeType): array
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

        return count($chunks) < 2
            ? $this->wholeFile($content, $codeType)
            : $chunks;
    }

    // =========================================================================
    // Function extraction
    // =========================================================================

    /**
     * Extract functions, arrow functions, and class methods.
     *
     * Matches:
     *   function myFunc(...) {
     *   async function myFunc(...) {
     *   export function myFunc(...) {
     *   const myFunc = (...) => {
     *   const myFunc = async (...) => {
     *   myMethod(...) {
     */
    private function chunkByFunctions(string $content, string $codeType): array
    {
        $chunks    = [];
        $className = $this->extractClassName($content);

        $pattern = '/
            (?:\/\*[\s\S]*?\*\/\s*)?
            (?:export\s+(?:default\s+)?)?
            (?:
                (?:async\s+)?function\s+(\w+)\s*\([^)]*\)\s*\{
                |
                (?:const|let)\s+(\w+)\s*=\s*(?:async\s*)?\([^)]*\)\s*=>\s*\{
                |
                (?:async\s+)?([a-zA-Z_$][\w$]*)\s*\([^)]*\)\s*\{
            )
        /x';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $startPos = $match[1];
            $funcName = $matches[1][$i][0] ?: $matches[2][$i][0] ?: $matches[3][$i][0];

            if (in_array($funcName, ['if', 'for', 'while', 'switch', 'catch', 'else'])) {
                continue;
            }

            $methodCode = $this->extractFunctionBody($content, $startPos);

            if ($methodCode && strlen(trim($methodCode)) > 30) {
                $chunks[] = [
                    'code'        => $methodCode,
                    'class_name'  => $className,
                    'method_name' => $funcName ?: null,
                    'code_type'   => $codeType,
                ];
            }
        }

        return empty($chunks)
            ? $this->wholeFile($content, $codeType)
            : $chunks;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Extract a function body by brace-matching.
     * Acceptable for MVP — tree-sitter replaces this in v3.
     */
    private function extractFunctionBody(string $content, int $startPos): ?string
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

    private function extractClassName(string $content): ?string
    {
        if (preg_match('/class\s+(\w+)/', $content, $match)) {
            return $match[1];
        }
        return null;
    }

    private function inferCodeType(string $filePath): string
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

    private function wholeFile(string $content, string $codeType = 'file'): array
    {
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => $codeType,
        ]];
    }
}