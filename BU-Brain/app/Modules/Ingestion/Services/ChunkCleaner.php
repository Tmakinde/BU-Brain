<?php

namespace App\Modules\Ingestion\Services;

class ChunkCleaner
{
    // Safe limit — leave headroom below the model's actual max
    // nomic-embed-text: 8192 max → we use 512 tokens as practical chunk ceiling
    // This keeps chunks focused and embeddings precise
    private const MAX_TOKENS = 512;

    // Approximate chars-per-token for code (code is denser than prose)
    private const CHARS_PER_TOKEN = 3;

    private const MAX_CHARS = self::MAX_TOKENS * self::CHARS_PER_TOKEN; // ~1536 chars

    /**
     * Clean a chunk and ensure it fits within the embedding model's token limit.
     * Returns one or more cleaned chunks — splits if oversized.
     */
    public function cleanAndGuard(array $chunk): array
    {
        $cleaned = $this->clean($chunk['code']);

        // If within limit, return single cleaned chunk
        if (strlen($cleaned) <= self::MAX_CHARS) {
            return [$chunk + ['code' => $cleaned]];
        }

        // Oversized — split by lines, preserving as much context as possible
        return $this->splitOversizedChunk($chunk, $cleaned);
    }

    /**
     * Clean code text — strip noise before embedding.
     */
    public function clean(string $code): string
    {
        // Strip PHPDoc blocks
        $code = preg_replace('/\/\*\*[\s\S]*?\*\//', '', $code);

        // Strip single-line comments
        $code = preg_replace('/\/\/[^\n]*/', '', $code);

        // Strip Python/bash style comments
        $code = preg_replace('/#[^\n]*/', '', $code);

        // Strip excess blank lines (max 1 consecutive blank line)
        $code = preg_replace('/\n{3,}/', "\n\n", $code);

        return trim($code);
    }

    /**
     * Split an oversized chunk into multiple smaller chunks.
     * Splits at line boundaries to avoid cutting mid-expression.
     * Preserves method_name and class_name on each sub-chunk.
     */
    private function splitOversizedChunk(array $chunk, string $cleanedCode): array
    {
        $lines    = explode("\n", $cleanedCode);
        $subChunks = [];
        $current  = [];
        $currentLen = 0;

        foreach ($lines as $line) {
            $lineLen = strlen($line) + 1; // +1 for newline

            if ($currentLen + $lineLen > self::MAX_CHARS && !empty($current)) {
                // Save current sub-chunk
                $subChunks[] = $this->buildSubChunk($chunk, implode("\n", $current));
                $current     = [];
                $currentLen  = 0;
            }

            $current[]   = $line;
            $currentLen += $lineLen;
        }

        // Catch remaining lines
        if (!empty($current)) {
            $subChunks[] = $this->buildSubChunk($chunk, implode("\n", $current));
        }

        return $subChunks;
    }

    /**
     * Build a sub-chunk preserving all original metadata.
     */
    private function buildSubChunk(array $originalChunk, string $code): array
    {
        return array_merge($originalChunk, [
            'code' => trim($code),
        ]);
    }
}