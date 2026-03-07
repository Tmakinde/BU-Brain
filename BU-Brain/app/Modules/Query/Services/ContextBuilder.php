<?php

namespace App\Modules\Query\Services;

use Illuminate\Support\Collection;
use App\Modules\Ingestion\Models\Chunk;

class ContextBuilder
{
    /**
     * Build formatted context from chunks for LLM consumption
     *
     * @param Collection $chunks
     * @return string
     */
    public function buildForLLM(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return "No relevant code found.";
        }

        $context = "# Relevant Code Context\n\n";

        // Group chunks by file
        $groupedChunks = $chunks->groupBy('file_path');

        foreach ($groupedChunks as $filePath => $fileChunks) {
            $firstChunk = $fileChunks->first();
            $appName = $firstChunk->app_name ?? 'unknown';
            
            $context .= "## {$appName}: {$filePath}\n";
            
            if ($firstChunk->code_type) {
                $context .= "**Type**: {$firstChunk->code_type}\n";
            }
            
            if ($firstChunk->tables_referenced && !empty($firstChunk->tables_referenced)) {
                $context .= "**Tables**: " . implode(', ', $firstChunk->tables_referenced) . "\n";
            }
            
            $context .= "\n```php\n";
            
            foreach ($fileChunks as $chunk) {
                $context .= $chunk->raw_text . "\n\n";
            }
            
            $context .= "```\n\n";
        }

        return $context;
    }

    /**
     * Build formatted context for CLI display
     *
     * @param Collection $chunks
     * @return string
     */
    public function buildForCLI(Collection $chunks): string
    {
        if ($chunks->isEmpty()) {
            return "No results found.";
        }

        $output = "\n";

        foreach ($chunks as $index => $chunk) {
            $appName = $chunk->app_name ?? 'unknown';
            $similarity = number_format($chunk->similarity * 100, 2);
            
            $output .= "────────────────────────────────────────────────────────────────\n";
            $output .= "Result #" . ($index + 1) . " | Similarity: {$similarity}%\n";
            $output .= "App: {$appName} | File: {$chunk->file_path}\n";
            
            if ($chunk->code_type) {
                $output .= "Type: {$chunk->code_type}\n";
            }
            
            if ($chunk->tables_referenced && !empty($chunk->tables_referenced)) {
                $output .= "Tables: " . implode(', ', $chunk->tables_referenced) . "\n";
            }
            
            $output .= "\n" . $chunk->raw_text . "\n";
        }
        
        $output .= "────────────────────────────────────────────────────────────────\n";
        $output .= "\nTotal results: " . $chunks->count() . "\n";

        return $output;
    }

    /**
     * Build JSON format for API responses
     *
     * @param Collection $chunks
     * @return array
     */
    public function buildForAPI(Collection $chunks): array
    {
        return [
            'total' => $chunks->count(),
            'results' => $chunks->map(function ($chunk) {
                return [
                    'id' => $chunk->id,
                    'app_name' => $chunk->app_name,
                    'file_path' => $chunk->file_path,
                    'class_name' => $chunk->class_name,
                    'method_name' => $chunk->method_name,
                    'code_type' => $chunk->code_type,
                    'content' => $chunk->raw_text,
                    'tables_referenced' => $chunk->tables_referenced ?? [],
                    'similarity' => $chunk->similarity,
                ];
            })->values()->toArray()
        ];
    }

    /**
     * Deduplicate overlapping chunks from the same file
     *
     * @param Collection $chunks
     * @return Collection
     */
    public function deduplicate(Collection $chunks): Collection
    {
        $seen = [];
        
        return $chunks->filter(function ($chunk) use (&$seen) {
            $key = $chunk->app_name . ':' . $chunk->file_path . ':' . $chunk->id;
            
            if (isset($seen[$key])) {
                return false;
            }
            
            $seen[$key] = true;
            return true;
        });
    }
}
