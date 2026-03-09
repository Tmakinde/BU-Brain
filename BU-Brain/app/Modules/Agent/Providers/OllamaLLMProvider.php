<?php

namespace App\Modules\Agent\Providers;

use App\Modules\Agent\Contracts\LLMProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaLLMProvider implements LLMProvider
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct(
        string $baseUrl = 'http://localhost:11434',
        string $model = 'llama3',
        int $timeout = 120
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Generate a chat completion from Ollama.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Additional options like temperature, max_tokens, etc.
     * @return string The generated response
     * @throws \Exception If the Ollama request fails
     */
    public function chat(array $messages, array $options = []): string
    {
        $url = "{$this->baseUrl}/api/chat";

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'options' => array_merge([
                'temperature' => 0.7,
                'num_ctx' => 8192, // Context window size
            ], $options),
        ];

        Log::info('Ollama LLM Request', [
            'url' => $url,
            'model' => $this->model,
            'message_count' => count($messages),
        ]);

        try {
            $response = Http::timeout($this->timeout)
                ->post($url, $payload);

            if (!$response->successful()) {
                throw new \Exception(
                    "Ollama API request failed: {$response->status()} - {$response->body()}"
                );
            }

            $data = $response->json();

            if (!isset($data['message']['content'])) {
                throw new \Exception('Invalid response format from Ollama API');
            }

            $content = $data['message']['content'];

            Log::info('Ollama LLM Response', [
                'length' => strlen($content),
                'eval_count' => $data['eval_count'] ?? null,
                'eval_duration' => $data['eval_duration'] ?? null,
            ]);

            return $content;
        } catch (\Exception $e) {
            Log::error('Ollama LLM Error', [
                'error' => $e->getMessage(),
                'model' => $this->model,
            ]);

            throw $e;
        }
    }

    /**
     * Check if Ollama is available/healthy.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Ollama availability check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get the model name being used.
     *
     * @return string
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set the model to use for chat.
     *
     * @param string $model
     * @return self
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }
}
