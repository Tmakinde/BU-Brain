<?php

namespace App\Modules\Embedding\Contracts;

interface EmbeddingProvider
{
    /**
     * Generate an embedding vector for the given text.
     *
     * @param string $text The text to embed
     * @return array The embedding vector (array of floats)
     */
    public function embed(string $text): array;

    /**
     * Get the dimension of the embedding vectors.
     *
     * @return int The number of dimensions
     */
    public function dimensions(): int;
}
