<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\Message;
use Generator;

/**
 * The workflow layer: it knows about sessions, messages and titles.
 *
 * OpenAIService knows how to talk to OpenAI but nothing about the database.
 * The controller knows about HTTP but nothing about either. This class is the
 * glue, and it is the only place the two halves meet.
 */
class ChatService
{
    public function __construct(
        protected OpenAIService $openai,
    ) {}

    public function startSession(): ChatSession
    {
        return ChatSession::create();
    }

    public function addUserMessage(ChatSession $session, string $content): Message
    {
        // touch() bumps updated_at so the session jumps to the top of the
        // sidebar, which is ordered by most recent activity.
        $session->touch();

        return $session->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $content,
        ]);
    }

    /**
     * Generate a reply in one shot and store it. Used by the no-JavaScript
     * fallback; the streaming path below is what the UI normally uses.
     */
    public function generateReply(ChatSession $session): Message
    {
        $reply = $this->openai->reply($this->history($session));

        return $this->storeReply($session, $reply);
    }

    /**
     * Generate a reply, yielding each chunk as it arrives, and store the whole
     * thing once the stream ends.
     *
     * Because the message is only written after the last chunk, a user who
     * closes the tab mid-answer simply leaves the session awaiting a reply --
     * we never persist half an answer.
     *
     * @return Generator<int, string>
     */
    public function streamReply(ChatSession $session): Generator
    {
        $reply = '';

        foreach ($this->openai->streamReply($this->history($session)) as $chunk) {
            $reply .= $chunk;

            yield $chunk;
        }

        if (trim($reply) !== '') {
            $this->storeReply($session, $reply);
        }
    }

    /**
     * Persist the assistant's answer and, for a brand new session, name it.
     */
    protected function storeReply(ChatSession $session, string $reply): Message
    {
        $message = $session->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => trim($reply),
        ]);

        $this->titleSession($session, $reply);

        $session->touch();

        return $message;
    }

    /**
     * A session gets its title once, from the first complete exchange.
     */
    protected function titleSession(ChatSession $session, string $reply): void
    {
        if (filled($session->title)) {
            return;
        }

        $firstUserMessage = $session->messages()
            ->where('role', Message::ROLE_USER)
            ->oldest('id')
            ->value('content');

        if (blank($firstUserMessage)) {
            return;
        }

        $session->update([
            'title' => $this->openai->generateTitle($firstUserMessage, $reply),
        ]);
    }

    /**
     * Turn stored rows into the array shape the OpenAI API expects.
     *
     * This IS the "AI memory": the model remembers nothing between calls, so
     * on every request we replay the last N messages of the conversation. Drop
     * this method and the bot forgets your name the moment you send it.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function history(ChatSession $session): array
    {
        return $session->messages()
            ->latest('id')                       // newest first, so the limit
            ->limit(config('chat.history_limit')) // keeps the RECENT messages
            ->get()
            ->reverse()                          // then back to chronological
            ->map(fn (Message $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }
}
