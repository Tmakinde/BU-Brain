<?php

namespace App\Providers;

use App\Modules\Agent\Contracts\LLMProvider;
use App\Modules\Agent\Providers\OllamaLLMProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register LLM Provider
        $this->app->singleton(LLMProvider::class, function ($app) {
            $config = config('llm.providers.ollama');
            
            return new OllamaLLMProvider(
                baseUrl: $config['base_url'],
                model: $config['model'],
                timeout: $config['timeout']
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
