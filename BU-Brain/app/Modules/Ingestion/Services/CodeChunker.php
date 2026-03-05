<?php

namespace App\Modules\Ingestion\Services;

use App\Modules\Ingestion\Chunkers\PhpChunker;
use App\Modules\Ingestion\Chunkers\JsChunker;
use App\Modules\Ingestion\Chunkers\SqlChunker;

/**
 * Entry point for file chunking.
 *
 * Detects the file type and delegates to the appropriate chunker.
 * Each chunker has one job and one file type to worry about.
 *
 * To add support for a new file type:
 *   1. Create a new chunker in App\Modules\Ingestion\Chunkers\
 *   2. Add a case here
 */
class CodeChunker
{
    public function __construct(
        private PhpChunker $phpChunker,
        private JsChunker  $jsChunker,
        private SqlChunker $sqlChunker,
    ) {}

    /**
     * Chunk a file into logical units for embedding.
     *
     * @param string $content  File content
     * @param string $filePath File path (used to determine file type)
     * @param string $stack    App stack ('laravel', 'yii', 'codeigniter', etc.)
     * @return array Array of chunks, each with: code, class_name, method_name, code_type
     */
    public function chunk(string $content, string $filePath, string $stack): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return match ($extension) {
            'php'      => $this->phpChunker->chunk($content, $filePath),
            'js', 'ts' => $this->jsChunker->chunk($content, $filePath),
            'sql'      => $this->sqlChunker->chunk($content, $filePath),
            default    => $this->wholeFile($content),
        };
    }

    private function wholeFile(string $content): array
    {
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => 'file',
        ]];
    }
}