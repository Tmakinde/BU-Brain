<?php

namespace App\Modules\Query\Services;

use App\Modules\Embedding\EmbeddingService as EmbeddingEmbeddingService;
use App\Modules\Embedding\Services\EmbeddingService;
use Illuminate\Support\Facades\Log;

class QueryService
{
    public function __construct(
        private EmbeddingEmbeddingService $embeddingService
    ) {}

    /**
     * Generate embedding for a user query
     *
     * @param string $query The user's question or search query
     * @return array The embedding vector (normalized to storage dimension)
     */
    public function embedQuery(string $query): array
    {
        Log::info('Generating embedding for query', [
            'query' => $query,
            'length' => strlen($query)
        ]);

        $embedding = $this->embeddingService->embed($query);

        Log::info('Query embedding generated', [
            'dimension' => count($embedding)
        ]);

        return $embedding;
    }

    /**
     * Validate and clean user query
     *
     * @param string $query
     * @return string
     */
    public function cleanQuery(string $query): string
    {
        // Trim whitespace
        $query = trim($query);

        // Remove excessive whitespace
        $query = preg_replace('/\s+/', ' ', $query);

        return $query;
    }
}
