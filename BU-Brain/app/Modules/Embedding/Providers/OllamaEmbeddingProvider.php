<?php

namespace App\Modules\Embedding\Providers;

use App\Modules\Embedding\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;

class OllamaEmbeddingProvider implements EmbeddingProvider
{
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('embedding.ollama.base_url', 'http://localhost:11434'), '/');
        $this->model = config('embedding.ollama.model', 'nomic-embed-text');
    }

    /**
     * Generate an embedding vector using Ollama.
     *
     * @param string $text The text to embed
     * @return array The embedding vector
     * @throws \Exception If the API call fails
     */
    public function embed(string $text): array
    {
        try {
            $response = Http::timeout(60)
                ->post("{$this->baseUrl}/api/embeddings", [
                    'model' => $this->model,
                    'prompt' => $text,
                ]);

            if (!$response->successful()) {
                throw new \Exception("Ollama API failed: " . $response->body());
            }

            $data = $response->json();

            if (!isset($data['embedding']) || !is_array($data['embedding'])) {
                throw new \Exception("Invalid response format from Ollama");
            }

            $embedding = $data['embedding'];
            $expected = $this->dimensions();

            // Validate dimension count
            if (count($embedding) !== $expected) {
                throw new \Exception(
                    "Dimension mismatch: expected {$expected} dimensions, got " . count($embedding)
                );
            }

            return $embedding;
        } catch (\Exception $e) {
            throw new \Exception("Failed to generate embedding: " . $e->getMessage());
        }
    }

    /**
     * Get the dimension of nomic-embed-text embeddings.
     *
     * @return int
     */
    public function dimensions(): int
    {
        // nomic-embed-text produces 768-dimensional vectors
        return 768;
    }
}
