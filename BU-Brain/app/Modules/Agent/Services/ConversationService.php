<?php

namespace App\Modules\Agent\Services;

use App\Modules\Agent\Models\Conversation;
use Illuminate\Support\Str;

class ConversationService
{
    /**
     * Start a new conversation session.
     *
     * @param string|null $appName Optional app context
     * @return string Session ID
     */
    public function startSession(?string $appName = null): string
    {
        $sessionId = Str::uuid()->toString();

        if ($appName) {
            // Store initial context message
            Conversation::create([
                'session_id' => $sessionId,
                'role' => 'system',
                'content' => "Working with app: {$appName}",
                'working_app' => $appName,
            ]);
        }

        return $sessionId;
    }

    /**
     * Get or create a session.
     *
     * @param string|null $sessionId
     * @param string|null $appName
     * @return string Session ID
     */
    public function getOrCreateSession(?string $sessionId = null, ?string $appName = null): string
    {
        if ($sessionId && $this->sessionExists($sessionId)) {
            return $sessionId;
        }

        return $this->startSession($appName);
    }

    /**
     * Check if a session exists.
     *
     * @param string $sessionId
     * @return bool
     */
    public function sessionExists(string $sessionId): bool
    {
        return Conversation::where('session_id', $sessionId)->exists();
    }

    /**
     * Add a user message to the conversation.
     *
     * @param string $sessionId
     * @param string $content
     * @param string|null $appName
     * @return Conversation
     */
    public function addUserMessage(string $sessionId, string $content, ?string $appName = null): Conversation
    {
        return Conversation::create([
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => $content,
            'working_app' => $appName,
        ]);
    }

    /**
     * Add an assistant message to the conversation.
     *
     * @param string $sessionId
     * @param string $content
     * @param string|null $appName
     * @return Conversation
     */
    public function addAssistantMessage(string $sessionId, string $content, ?string $appName = null): Conversation
    {
        return Conversation::create([
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => $content,
            'working_app' => $appName,
        ]);
    }

    /**
     * Get conversation history for a session.
     *
     * @param string $sessionId
     * @param int|null $limit Limit number of messages (null = all)
     * @return \Illuminate\Support\Collection
     */
    public function getHistory(string $sessionId, ?int $limit = null)
    {
        $query = Conversation::where('session_id', $sessionId)
            ->orderBy('created_at', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->reverse()->values();
    }

    /**
     * Format conversation history for LLM context.
     * Returns array of messages in OpenAI-compatible format.
     *
     * @param string $sessionId
     * @param int $limit
     * @return array
     */
    public function formatForLLM(string $sessionId, int $limit = 10): array
    {
        $messages = $this->getHistory($sessionId, $limit);

        return $messages->map(function ($message) {
            return [
                'role' => $message->role,
                'content' => $message->content,
            ];
        })->toArray();
    }

    /**
     * Get the app context from a session.
     *
     * @param string $sessionId
     * @return string|null
     */
    public function getSessionApp(string $sessionId): ?string
    {
        return Conversation::where('session_id', $sessionId)
            ->whereNotNull('working_app')
            ->latest()
            ->value('working_app');
    }

    /**
     * Clear a conversation session.
     *
     * @param string $sessionId
     * @return int Number of messages deleted
     */
    public function clearSession(string $sessionId): int
    {
        return Conversation::where('session_id', $sessionId)->delete();
    }

    /**
     * Get recent sessions.
     *
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getRecentSessions(int $limit = 10)
    {
        return Conversation::select('session_id', 'working_app')
            ->selectRaw('MIN(created_at) as started_at')
            ->selectRaw('MAX(created_at) as last_activity')
            ->selectRaw('COUNT(*) as message_count')
            ->groupBy('session_id', 'working_app')
            ->orderBy('last_activity', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get a summary of a session (first user message).
     *
     * @param string $sessionId
     * @return string|null
     */
    public function getSessionSummary(string $sessionId): ?string
    {
        $firstMessage = Conversation::where('session_id', $sessionId)
            ->where('role', 'user')
            ->oldest()
            ->first();

        if (!$firstMessage) {
            return null;
        }

        $preview = Str::limit($firstMessage->content, 60);
        return $preview;
    }
}
