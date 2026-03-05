<?php

namespace App\Modules\Ingestion\Chunkers;

/**
 * Chunks SQL files into logical units.
 *
 * Splits by semicolons — one chunk per SQL statement.
 * Each statement is a complete, self-contained query.
 */
class SqlChunker
{
    public function chunk(string $content, string $filePath): array
    {
        $chunks     = [];
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

        return empty($chunks)
            ? $this->wholeFile($content)
            : $chunks;
    }

    private function wholeFile(string $content): array
    {
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => 'query',
        ]];
    }
}