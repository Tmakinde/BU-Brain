<?php

namespace App\Modules\Agent\Services;

use App\Modules\Agent\Contracts\LLMProvider;
use App\Modules\Embedding\Services\EmbeddingService;
use App\Modules\Query\Services\ContextBuilder;
use App\Modules\Query\Services\QueryService;
use App\Modules\Query\Services\RetrievalService;
use Illuminate\Support\Facades\Log;

class AgentService
{
    public function __construct(
        private ConversationService $conversationService,
        private QueryService $queryService,
        private RetrievalService $retrievalService,
        private ContextBuilder $contextBuilder,
        private LLMProvider $llmProvider
    ) {}

    /**
     * Process a user query and generate a response.
     *
     * @param string $query User's question
     * @param string|null $sessionId Conversation session ID
     * @param string|null $appName Target application name
     * @param array $options Additional options
     * @return array ['response' => string, 'session_id' => string, 'chunks_used' => int]
     */
    public function ask(
        string $query,
        ?string $sessionId = null,
        ?string $appName = null,
        array $options = []
    ): array {
        // Get or create session
        $sessionId = $this->conversationService->getOrCreateSession($sessionId, $appName);

        // If no app specified, try to get from session context
        if (!$appName) {
            $appName = $this->conversationService->getSessionApp($sessionId);
        }

        // Store user message
        $this->conversationService->addUserMessage($sessionId, $query, $appName);

        Log::info('Agent processing query', [
            'session_id' => $sessionId,
            'app_name' => $appName,
            'query' => $query,
        ]);

        // Step 1: Embed the query
        $queryEmbedding = $this->queryService->embedQuery($query);

        // Step 2: Retrieve relevant code chunks
        $limit = $options['max_chunks'] ?? config('llm.context.max_chunks', 10);
        $minSimilarity = $options['min_similarity'] ?? config('llm.context.min_similarity', 0.5);

        $results = $this->retrievalService->search($queryEmbedding, [
            'app_name' => $appName,
            'limit' => $limit,
            'min_similarity' => $minSimilarity,
        ]);

        Log::info('Retrieved code chunks', [
            'count' => count($results),
            'app_name' => $appName,
        ]);

        // Step 3: Build context for LLM
        $codeContext = $this->contextBuilder->buildForLLM($results);

        // Step 4: Get conversation history
        $historyLimit = $options['conversation_history_limit'] 
            ?? config('llm.context.conversation_history_limit', 10);
        $conversationHistory = $this->conversationService->formatForLLM($sessionId, $historyLimit);

        // Step 5: Build messages for LLM
        $messages = $this->buildLLMMessages(
            query: $query,
            codeContext: $codeContext,
            conversationHistory: $conversationHistory,
            systemPrompt: $options['system_prompt'] ?? config('llm.system_prompts.code_explanation')
        );

        // Step 6: Generate response from LLM
        $llmOptions = array_merge(
            config('llm.providers.ollama.options', []),
            $options['llm_options'] ?? []
        );

        $response = $this->llmProvider->chat($messages, $llmOptions);

        Log::info('LLM generated response', [
            'session_id' => $sessionId,
            'response_length' => strlen($response),
        ]);

        // Step 7: Store assistant response
        $this->conversationService->addAssistantMessage($sessionId, $response, $appName);

        return [
            'response' => $response,
            'session_id' => $sessionId,
            'chunks_used' => count($results),
            'app_name' => $appName,
        ];
    }

    /**
     * Build messages array for LLM.
     *
     * @param string $query
     * @param string $codeContext
     * @param array $conversationHistory
     * @param string $systemPrompt
     * @return array
     */
    private function buildLLMMessages(
        string $query,
        string $codeContext,
        array $conversationHistory,
        string $systemPrompt
    ): array {
        $messages = [];

        // System prompt
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        // Add code context as a system message
        if (!empty($codeContext)) {
            $messages[] = [
                'role' => 'system',
                'content' => "Here is relevant code context from the codebase:\n\n{$codeContext}",
            ];
        }

        // Add conversation history (excluding system messages and the current query)
        foreach ($conversationHistory as $message) {
            if ($message['role'] !== 'system') {
                $messages[] = $message;
            }
        }

        // Current query is already in history, so we don't add it again
        // The conversation service already stored it before we build messages

        return $messages;
    }

    /**
     * Check if the agent is ready to operate.
     *
     * @return array ['ready' => bool, 'issues' => array]
     */
    public function healthCheck(): array
    {
        $issues = [];

        // Check LLM provider
        if (!$this->llmProvider->isAvailable()) {
            $issues[] = 'LLM provider is not available';
        }

        // Check embedding service
        try {
            $testEmbedding = $this->queryService->embedQuery('test');
            if (count($testEmbedding) !== 1536) {
                $issues[] = 'Embedding service returned incorrect dimension';
            }
        } catch (\Exception $e) {
            $issues[] = "Embedding service error: {$e->getMessage()}";
        }

        return [
            'ready' => empty($issues),
            'issues' => $issues,
            'llm_model' => $this->llmProvider->getModel(),
        ];
    }

    /**
     * Get conversation service instance.
     *
     * @return ConversationService
     */
    public function getConversationService(): ConversationService
    {
        return $this->conversationService;
    }
}
