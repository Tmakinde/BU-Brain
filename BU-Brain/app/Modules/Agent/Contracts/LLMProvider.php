<?php

namespace App\Modules\Agent\Contracts;

interface LLMProvider
{
    /**
     * Generate a chat completion from the LLM.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Additional options like temperature, max_tokens, etc.
     * @return string The generated response
     * @throws \Exception If the LLM request fails
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Check if the provider is available/healthy.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get the model name being used.
     *
     * @return string
     */
    public function getModel(): string;
}
