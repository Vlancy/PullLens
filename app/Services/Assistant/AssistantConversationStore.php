<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Cache;

/**
 * Per-user storage for the AI assistant's conversation history.
 *
 * The server is the authority on what was said. The browser sends its own copy for
 * rendering, but it is never trusted as input to the model — otherwise a crafted
 * request could put words in the assistant's mouth and steer subsequent answers.
 */
class AssistantConversationStore
{
    /** Maximum turns retained; older ones are dropped from the front. */
    private const MAX_TURNS = 50;

    /** How long an idle conversation survives. */
    private const RETENTION_DAYS = 7;

    /**
     * The stored conversation for a user.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function get(int|string $userId): array
    {
        return (array) Cache::get($this->key($userId), []);
    }

    /**
     * Append a user turn and the assistant's answer, trimming to the retention window.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function append(int|string $userId, string $message, string $answer): array
    {
        $history = [
            ...$this->get($userId),
            ['role' => 'user', 'content' => $message],
            ['role' => 'assistant', 'content' => $answer],
        ];

        $history = array_slice($history, -self::MAX_TURNS);

        Cache::put($this->key($userId), $history, now()->addDays(self::RETENTION_DAYS));

        return $history;
    }

    /**
     * Discard a user's conversation.
     */
    public function clear(int|string $userId): void
    {
        Cache::forget($this->key($userId));
    }

    /**
     * Cache key for a user's conversation. Namespaced by user id so one session can
     * never read another's history.
     */
    private function key(int|string $userId): string
    {
        return "assistant_history:{$userId}";
    }
}
