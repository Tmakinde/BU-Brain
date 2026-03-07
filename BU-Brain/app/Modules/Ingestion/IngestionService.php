<?php

namespace App\Modules\Ingestion;

use App\Modules\Embedding\EmbeddingService;
use App\Modules\Ingestion\Models\Chunk;
use App\Modules\Ingestion\Services\ChunkCleaner;
use App\Modules\Ingestion\Services\CodeChunker;
use App\Modules\Ingestion\Services\FileFilter;
use App\Modules\Ingestion\Services\LocalFileReader;
use App\Modules\Ingestion\Services\MetadataExtractor;
use App\Modules\Registry\Models\AppRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngestionService
{
    public function __construct(
        private LocalFileReader $fileReader,
        private FileFilter $fileFilter,
        private CodeChunker $codeChunker,
        private ChunkCleaner $chunkCleaner,
        private MetadataExtractor $metadataExtractor,
        private EmbeddingService $embeddingService,
    ) {}

    /**
     * Index an entire application from the registry.
     *
     * @param string $appName The app name from app_registry
     * @return array Statistics about the indexing operation
     */
    public function indexApp(string $appName): array
    {
        $app = AppRegistry::where('name', $appName)->first();

        if (!$app) {
            throw new \InvalidArgumentException("App '{$appName}' not found in registry");
        }

        Log::info("Starting indexing for app: {$appName}", [
            'source_path' => $app->source_path,
        ]);

        $stats = [
            'app_name' => $appName,
            'files_read' => 0,
            'files_filtered' => 0,
            'chunks_created' => 0,
            'chunks_stored' => 0,
            'errors' => 0,
            'started_at' => now(),
        ];

        try {
            // Step 1: Read all files from source path
            Log::info("Reading files from: {$app->source_path}");
            $files = $this->fileReader->readFiles($app->source_path);
            $stats['files_read'] = count($files);

            // Step 2: Filter files based on app rules
            Log::info("Filtering files", ['count' => count($files)]);
            $filteredFiles = $this->fileFilter->filter($files, $app->file_filter_rules);
            $stats['files_filtered'] = count($filteredFiles);

            Log::info("Files after filtering: {$stats['files_filtered']}");

            // Step 3: Clear existing chunks for this app (re-indexing)
            $this->clearExistingChunks($appName);

            // Step 4: Process each file
            foreach ($filteredFiles as $file) {
                try {
                    $fileChunks = $this->processFile($file, $app);
                    $stats['chunks_created'] += count($fileChunks);
                    $stats['chunks_stored'] += count($fileChunks);
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::warning("Failed to process file: {$file['path']}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Step 5: Mark app as indexed
            $app->update(['last_indexed_at' => now()]);

            $stats['completed_at'] = now();
            $stats['duration_seconds'] = $stats['started_at']->diffInSeconds($stats['completed_at']);

            Log::info("Indexing complete for app: {$appName}", $stats);

            return $stats;
        } catch (\Exception $e) {
            Log::error("Indexing failed for app: {$appName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Process a single file through the full pipeline.
     *
     * @param array $file ['path' => string, 'content' => string]
     * @param AppRegistry $app
     * @return array Array of created Chunk models
     */
    private function processFile(array $file, AppRegistry $app): array
    {
        $filePath = $file['path'];
        $content = $file['content'];
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        // Step 1: Chunk the file into logical units
        $chunks = $this->codeChunker->chunk($content, $filePath, $app->stack);

        if (empty($chunks)) {
            return [];
        }

        $storedChunks = [];

        foreach ($chunks as $chunkData) {
            try {
                // Step 2: Clean the chunk
                $cleanedCode = $this->chunkCleaner->clean($chunkData['code'], $extension);

                // Skip if chunk is too small after cleaning
                if (strlen(trim($cleanedCode)) < 30) {
                    continue;
                }

                // Step 3: Extract metadata
                $metadata = $this->metadataExtractor->extract($cleanedCode, $filePath, $chunkData);

                // Step 4: Generate embedding
                $embedding = $this->embeddingService->embed($cleanedCode);

                // Step 5: Store in database
                $chunk = Chunk::create([
                    'app_name' => $app->name,
                    'file_path' => $metadata['file_path'],
                    'class_name' => $metadata['class_name'],
                    'method_name' => $metadata['method_name'],
                    'code_type' => $metadata['code_type'],
                    'raw_text' => $cleanedCode,
                    'tables_referenced' => $metadata['tables_referenced'],
                    'embedding' => $embedding,
                ]);

                $storedChunks[] = $chunk;
            } catch (\Exception $e) {
                Log::warning("Failed to process chunk in file: {$filePath}", [
                    'class' => $chunkData['class_name'] ?? null,
                    'method' => $chunkData['method_name'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $storedChunks;
    }

    /**
     * Clear existing chunks for an app (for re-indexing).
     *
     * @param string $appName
     */
    private function clearExistingChunks(string $appName): void
    {
        $deleted = Chunk::where('app_name', $appName)->delete();
        
        if ($deleted > 0) {
            Log::info("Cleared existing chunks for re-indexing", [
                'app_name' => $appName,
                'deleted_count' => $deleted,
            ]);
        }
    }

    /**
     * Get indexing statistics for an app.
     *
     * @param string $appName
     * @return array
     */
    public function getAppStats(string $appName): array
    {
        $app = AppRegistry::where('name', $appName)->first();

        if (!$app) {
            throw new \InvalidArgumentException("App '{$appName}' not found in registry");
        }

        $totalChunks = Chunk::where('app_name', $appName)->count();
        
        $chunksByType = Chunk::where('app_name', $appName)
            ->select('code_type', DB::raw('count(*) as count'))
            ->groupBy('code_type')
            ->pluck('count', 'code_type')
            ->toArray();

        return [
            'app_name' => $appName,
            'last_indexed_at' => $app->last_indexed_at,
            'total_chunks' => $totalChunks,
            'chunks_by_type' => $chunksByType,
        ];
    }
}
