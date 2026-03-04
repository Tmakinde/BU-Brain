<?php

namespace App\Modules\Ingestion\Services;

class ChunkCleaner
{
    /**
     * Clean a code chunk by removing noise while preserving meaningful code.
     *
     * @param string $code Raw code chunk
     * @param string $extension File extension (php, js, ts, py, sql)
     * @return string Cleaned code
     */
    public function clean(string $code, string $extension): string
    {
        return match ($extension) {
            'php' => $this->cleanPhp($code),
            'js', 'ts' => $this->cleanJavaScript($code),
            'sql' => $this->cleanSql($code),
            default => $this->cleanGeneric($code),
        };
    }

    /**
     * Clean PHP code.
     */
    private function cleanPhp(string $code): string
    {
        // Remove PHPDoc blocks
        $code = preg_replace('/\/\*\*[\s\S]*?\*\//', '', $code);

        // Remove multi-line comments
        $code = preg_replace('/\/\*[\s\S]*?\*\//', '', $code);

        // Remove single-line comments (be careful with URLs)
        $code = preg_replace('/(?<!:)\/\/.*$/m', '', $code);

        // Remove excessive blank lines (keep max 1)
        $code = preg_replace('/\n{3,}/', "\n\n", $code);

        // Remove leading/trailing whitespace from each line while preserving indentation structure
        $lines = explode("\n", $code);
        $lines = array_map('rtrim', $lines);
        $code = implode("\n", $lines);

        return trim($code);
    }

    /**
     * Clean JavaScript/TypeScript code.
     */
    private function cleanJavaScript(string $code): string
    {
        // Remove JSDoc blocks
        $code = preg_replace('/\/\*\*[\s\S]*?\*\//', '', $code);

        // Remove multi-line comments
        $code = preg_replace('/\/\*[\s\S]*?\*\//', '', $code);

        // Remove single-line comments
        $code = preg_replace('/(?<!:)\/\/.*$/m', '', $code);

        // Remove excessive blank lines
        $code = preg_replace('/\n{3,}/', "\n\n", $code);

        return trim($code);
    }

    /**
     * Clean SQL code.
     */
    private function cleanSql(string $code): string
    {
        // Remove SQL comments
        $code = preg_replace('/--.*$/m', '', $code);
        $code = preg_replace('/\/\*[\s\S]*?\*\//', '', $code);

        // Remove excessive blank lines
        $code = preg_replace('/\n{3,}/', "\n\n", $code);

        return trim($code);
    }

    /**
     * Generic cleaning for unknown file types.
     */
    private function cleanGeneric(string $code): string
    {
        // Just remove excessive blank lines
        $code = preg_replace('/\n{3,}/', "\n\n", $code);

        return trim($code);
    }
}
