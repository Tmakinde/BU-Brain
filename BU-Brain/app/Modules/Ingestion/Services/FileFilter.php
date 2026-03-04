<?php

namespace App\Modules\Ingestion\Services;

class FileFilter
{
    /**
     * Filter files based on the app's filter rules.
     *
     * @param array $files Array of ['path' => string, 'content' => string]
     * @param array $rules Filter rules from app_registry.file_filter_rules
     * @return array Filtered files
     */
    public function filter(array $files, array $rules): array
    {
        $extensions = $rules['extensions'] ?? [];
        $includePaths = $rules['include_paths'] ?? [];
        $excludePaths = $rules['exclude_paths'] ?? [];
        $excludeFiles = $rules['exclude_files'] ?? [];

        return array_filter($files, function ($file) use ($extensions, $includePaths, $excludePaths, $excludeFiles) {
            $path = $file['path'];

            // Check file extension
            if (!$this->hasAllowedExtension($path, $extensions)) {
                return false;
            }

            // Check if file is in exclude list
            if ($this->isExcludedFile($path, $excludeFiles)) {
                return false;
            }

            // Check if path is in excluded paths
            if ($this->isInExcludedPath($path, $excludePaths)) {
                return false;
            }

            // Check if path is in included paths (if include paths are specified)
            if (!empty($includePaths) && !$this->isInIncludedPath($path, $includePaths)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Check if file has an allowed extension.
     */
    private function hasAllowedExtension(string $path, array $extensions): bool
    {
        if (empty($extensions)) {
            return true;
        }

        foreach ($extensions as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if file is in the exclude files list.
     */
    private function isExcludedFile(string $path, array $excludeFiles): bool
    {
        $filename = basename($path);

        return in_array($filename, $excludeFiles, true);
    }

    /**
     * Check if path is within an excluded directory.
     */
    private function isInExcludedPath(string $path, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            if (str_starts_with($path, $excludePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if path is within an included directory.
     */
    private function isInIncludedPath(string $path, array $includePaths): bool
    {
        foreach ($includePaths as $includePath) {
            if (str_starts_with($path, $includePath)) {
                return true;
            }
        }

        return false;
    }
}
