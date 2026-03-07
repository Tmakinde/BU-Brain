<?php

namespace App\Modules\Query\Services;

use App\Modules\Ingestion\Models\Chunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RetrievalService
{
    /**
     * Search for similar chunks using vector similarity
     *
     * @param array $queryEmbedding The query embedding vector
     * @param array $options Search options
     * @return Collection
     */
    public function search(array $queryEmbedding, array $options = []): Collection
    {
        $appName = $options['app_name'] ?? null;
        $limit = $options['limit'] ?? 10;
        $minSimilarity = $options['min_similarity'] ?? 0.5;

        Log::info('Searching for similar chunks', [
            'app_name' => $appName,
            'limit' => $limit,
            'min_similarity' => $minSimilarity
        ]);

        $query = Chunk::query()
            ->similarTo($queryEmbedding, $limit);

        // Filter by app if specified
        if ($appName) {
            $query->where('app_name', $appName);
        }

        $results = $query->get();

        // Filter by minimum similarity score
        $filtered = $results->filter(function ($chunk) use ($minSimilarity) {
            return $chunk->similarity >= $minSimilarity;
        });

        Log::info('Search completed', [
            'total_results' => $results->count(),
            'filtered_results' => $filtered->count()
        ]);

        return $filtered;
    }

    /**
     * Search across multiple apps
     *
     * @param array $queryEmbedding
     * @param array $appNames
     * @param int $limit Results per app
     * @return Collection
     */
    public function searchMultipleApps(array $queryEmbedding, array $appNames, int $limit = 5): Collection
    {
        $allResults = collect();

        foreach ($appNames as $appName) {
            $results = $this->search($queryEmbedding, [
                'app_name' => $appName,
                'limit' => $limit
            ]);

            $allResults = $allResults->merge($results);
        }

        // Re-sort by similarity across all apps
        return $allResults->sortByDesc('similarity')->values();
    }

    /**
     * Get context around a chunk (neighboring chunks from same file)
     *
     * @param Chunk $chunk
     * @param int $before Number of chunks before
     * @param int $after Number of chunks after
     * @return Collection
     */
    public function getContext(Chunk $chunk, int $before = 1, int $after = 1): Collection
    {
        $chunks = Chunk::query()
            ->where('app_name', $chunk->app_name)
            ->where('file_path', $chunk->file_path)
            ->orderBy('id')
            ->get();

        $currentIndex = $chunks->search(fn($c) => $c->id === $chunk->id);

        if ($currentIndex === false) {
            return collect([$chunk]);
        }

        $start = max(0, $currentIndex - $before);
        $end = min($chunks->count() - 1, $currentIndex + $after);

        return $chunks->slice($start, $end - $start + 1)->values();
    }
}
