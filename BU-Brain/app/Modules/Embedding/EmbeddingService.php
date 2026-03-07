<?php

namespace App\Modules\Embedding;

use App\Modules\Embedding\Contracts\EmbeddingProvider;

class EmbeddingService
{
    private EmbeddingProvider $provider;

    /**
     * Target dimension for storage.
     * Set to 1536 to support both Ollama (768) and OpenAI (1536).
     */
    private const STORAGE_DIMENSION = 1536;

    public function __construct(EmbeddingProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Generate an embedding for the given text.
     * Returns a vector normalized to STORAGE_DIMENSION.
     *
     * @param string $text The text to embed
     * @return array The embedding vector (normalized to 1536 dimensions)
     */
    public function embed(string $text): array
    {
        // Truncate text if it's too long (Ollama has token limits)
        $text = $this->truncateText($text, 8000);

        $embedding = $this->provider->embed($text);

        // Normalize to storage dimension (zero-pad if needed)
        return $this->normalizeToStorageDimension($embedding);
    }

    /**
     * Generate embeddings for multiple texts in batch.
     *
     * @param array $texts Array of text strings
     * @return array Array of embedding vectors
     */
    public function embedBatch(array $texts): array
    {
        $embeddings = [];

        foreach ($texts as $text) {
            $embeddings[] = $this->embed($text);
        }

        return $embeddings;
    }

    /**
     * Get the dimension of the embedding vectors.
     *
     * @return int
     */
    public function dimensions(): int
    {
        return $this->provider->dimensions();
    }

    /**
     * Normalize embedding to storage dimension.
     * Zero-pads shorter embeddings (e.g., Ollama 768 → 1536).
     * Truncates longer embeddings (shouldn't happen with proper providers).
     *
     * @param array $embedding Original embedding vector
     * @return array Normalized embedding vector
     */
    private function normalizeToStorageDimension(array $embedding): array
    {
        $currentDim = count($embedding);
        $targetDim = self::STORAGE_DIMENSION;

        if ($currentDim === $targetDim) {
            return $embedding;
        }

        if ($currentDim < $targetDim) {
            // Zero-pad to target dimension
            return array_merge($embedding, array_fill(0, $targetDim - $currentDim, 0.0));
        }

        // Truncate if somehow longer (shouldn't happen)
        return array_slice($embedding, 0, $targetDim);
    }

    /**
     * Truncate text to a maximum length.
     *
     * @param string $text
     * @param int $maxChars
     * @return string
     */
    private function truncateText(string $text, int $maxChars): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, $maxChars);
    }
}
