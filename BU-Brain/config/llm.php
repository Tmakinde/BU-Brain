<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default LLM Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default LLM provider that will be used for
    | conversational AI features in BU-Brain.
    |
    */
    'default' => env('LLM_PROVIDER', 'ollama'),

    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure the LLM providers used by your application.
    |
    */
    'providers' => [
        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_CHAT_MODEL', 'qwen2.5-coder:1.5b'),
            'timeout' => env('OLLAMA_TIMEOUT', 120),
            
            // Chat options
            'options' => [
                'temperature' => env('OLLAMA_TEMPERATURE', 0.7),
                'num_ctx' => env('OLLAMA_CONTEXT_SIZE', 8192),
                'repeat_penalty' => env('OLLAMA_REPEAT_PENALTY', 1.1),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Prompts
    |--------------------------------------------------------------------------
    |
    | System prompts that define the AI assistant's behavior and capabilities.
    |
    */
    'system_prompts' => [
        'default' => 'You are BU-Brain, an AI assistant specialized in analyzing codebases. You help developers understand code by providing clear, accurate explanations based on the codebase context provided to you. Be concise, technical, and helpful.',
        
        'code_explanation' => 'You are analyzing a codebase. Based on the provided code snippets and context, answer the user\'s question accurately and concisely. Reference specific files, classes, and methods when explaining. If the context doesn\'t contain enough information, say so.',
        
        'architecture' => 'You are explaining software architecture. Focus on high-level design patterns, component relationships, and system flow. Use technical terminology appropriate for experienced developers.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Settings
    |--------------------------------------------------------------------------
    |
    | Settings for how context is provided to the LLM.
    |
    */
    'context' => [
        // Maximum number of code chunks to include in context
        'max_chunks' => env('LLM_MAX_CHUNKS', 10),
        
        // Minimum similarity score for chunks to be included
        'min_similarity' => env('LLM_MIN_SIMILARITY', 0.5),
        
        // Maximum tokens for context (approximate)
        'max_context_tokens' => env('LLM_MAX_CONTEXT_TOKENS', 4000),
        
        // Number of conversation history messages to include
        'conversation_history_limit' => env('LLM_CONVERSATION_HISTORY', 10),
    ],
];
