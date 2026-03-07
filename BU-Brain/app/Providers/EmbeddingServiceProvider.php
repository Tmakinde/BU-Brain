<?php

namespace App\Providers;

use App\Modules\Embedding\Contracts\EmbeddingProvider;
use App\Modules\Embedding\EmbeddingService;
use App\Modules\Embedding\Providers\OllamaEmbeddingProvider;
use Illuminate\Support\ServiceProvider;

class EmbeddingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind the embedding provider based on config
        $this->app->bind(EmbeddingProvider::class, function ($app) {
            $provider = config('embedding.provider', 'ollama');

            return match ($provider) {
                'ollama' => new OllamaEmbeddingProvider(),
                // 'openai' => new OpenAIEmbeddingProvider(), // TODO: Implement for production
                default => throw new \InvalidArgumentException("Unsupported embedding provider: {$provider}"),
            };
        });

        // Bind the embedding service
        $this->app->singleton(EmbeddingService::class, function ($app) {
            return new EmbeddingService($app->make(EmbeddingProvider::class));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
