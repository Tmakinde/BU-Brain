<?php

namespace App\Modules\Agent\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'conversations';

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'working_app',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Get all messages for a session, ordered chronologically
     */
    public static function getSession(string $sessionId)
    {
        return self::where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get recent messages for context (last N messages)
     */
    public static function getRecentMessages(string $sessionId, int $limit = 10)
    {
        return self::where('session_id', $sessionId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Add a user message to the conversation
     */
    public static function addUserMessage(string $sessionId, string $content, ?string $appName = null): self
    {
        return self::create([
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => $content,
            'working_app' => $appName,
        ]);
    }

    /**
     * Add an assistant message to the conversation
     */
    public static function addAssistantMessage(string $sessionId, string $content, ?string $appName = null): self
    {
        return self::create([
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => $content,
            'working_app' => $appName,
        ]);
    }

    /**
     * Clear all messages in a session
     */
    public static function clearSession(string $sessionId): int
    {
        return self::where('session_id', $sessionId)->delete();
    }

    /**
     * Get the app context for a session (last mentioned app)
     */
    public static function getSessionAppContext(string $sessionId): ?string
    {
        $message = self::where('session_id', $sessionId)
            ->whereNotNull('working_app')
            ->orderBy('created_at', 'desc')
            ->first();

        return $message?->working_app;
    }
}
