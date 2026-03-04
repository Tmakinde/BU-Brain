<?php

namespace App\Modules\Ingestion\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LocalFileReader
{
    /**
     * Read all files from a local directory path.
     *
     * @param string $basePath The root path of the repository
     * @return array Array of ['path' => relative_path, 'content' => file_content]
     */
    public function readFiles(string $basePath): array
    {
        $files = [];
        $basePath = rtrim($basePath, '/');

        if (!is_dir($basePath)) {
            throw new \InvalidArgumentException("Directory does not exist: {$basePath}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->isReadable()) {
                $fullPath = $file->getPathname();
                $relativePath = str_replace($basePath . '/', '', $fullPath);

                $content = file_get_contents($fullPath);
                
                if ($content !== false) {
                    $files[] = [
                        'path' => $relativePath,
                        'content' => $content,
                    ];
                }
            }
        }

        return $files;
    }
}
