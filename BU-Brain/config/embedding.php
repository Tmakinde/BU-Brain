<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Embedding Provider
    |--------------------------------------------------------------------------
    |
    | The embedding provider to use for generating text embeddings.
    | Supported: "ollama", "openai"
    |
    */

    'provider' => env('EMBEDDING_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    */

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration (for production)
    |--------------------------------------------------------------------------
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],
];
