<?php

namespace App\Services;

use App\Exceptions\ChatServiceException;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

/**
 * The one and only place in this application that talks to OpenAI.
 *
 * Controllers hand it a conversation and get back text. They never see the
 * SDK, the model name, or an OpenAI exception. That is the whole idea of the
 * service pattern: if OpenAI changes its API tomorrow, only this file changes.
 */
class OpenAIService
{
    /**
     * Ask for a complete reply and wait for all of it.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function reply(array $history): string
    {
        if ($this->isFake()) {
            return $this->fakeReply($history);
        }

        try {
            $response = OpenAI::chat()->create($this->payload($history));
        } catch (Throwable $e) {
            throw $this->fail($e);
        }

        return trim($response->choices[0]->message->content ?? '');
    }

    /**
     * Ask for a reply and yield it piece by piece as it arrives.
     *
     * This is what makes the UI feel like ChatGPT: instead of staring at a
     * spinner for ten seconds, the user sees words appear. The controller
     * pushes each yielded chunk down an SSE connection.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return Generator<int, string>
     */
    public function streamReply(array $history): Generator
    {
        if ($this->isFake()) {
            yield from $this->fakeStream($history);

            return;
        }

        try {
            $stream = OpenAI::chat()->createStreamed($this->payload($history));

            foreach ($stream as $response) {
                $chunk = $response->choices[0]->delta->content ?? '';

                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } catch (Throwable $e) {
            throw $this->fail($e);
        }
    }

    /**
     * Name a conversation from its opening exchange, the way ChatGPT does.
     *
     * A failure here must never break the chat itself: a chat with a boring
     * title still works, so we fall back to a truncated first message.
     */
    public function generateTitle(string $userMessage, string $assistantMessage): string
    {
        $fallback = Str::limit(strip_tags($userMessage), 40);

        if ($this->isFake()) {
            return $fallback;
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => config('chat.model'),
                'max_tokens' => 20,
                'temperature' => 0.2,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Summarise the conversation as a title of at most 5 words. Reply with the title only: no quotes, no punctuation at the end.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "User: {$userMessage}\n\nAssistant: ".Str::limit($assistantMessage, 500),
                    ],
                ],
            ]);

            $title = trim($response->choices[0]->message->content ?? '', " \t\n\r\0\x0B\"'.");

            return Str::limit($title ?: $fallback, 60);
        } catch (Throwable $e) {
            Log::warning('Auto-title failed, falling back to the first message.', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /**
     * Build the request body. The system prompt is prepended here, on every
     * call, so no caller can forget it.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    protected function payload(array $history): array
    {
        return [
            'model' => config('chat.model'),
            'temperature' => config('chat.temperature'),
            'max_tokens' => config('chat.max_tokens'),
            'messages' => [
                ['role' => 'system', 'content' => config('chat.system_prompt')],
                ...$history,
            ],
        ];
    }

    /**
     * Log the real error for us, return a clean one for the user.
     */
    protected function fail(Throwable $e): ChatServiceException
    {
        Log::error('OpenAI request failed.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        if (blank(config('openai.api_key'))) {
            return ChatServiceException::missingApiKey();
        }

        return ChatServiceException::fromApi($e);
    }

    protected function isFake(): bool
    {
        return (bool) config('chat.fake');
    }

    /**
     * Demo mode: pretend to be an AI so the app can be run without a key.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    protected function fakeReply(array $history): string
    {
        $last = end($history)['content'] ?? '';
        $turns = count($history);

        return <<<MARKDOWN
        **Fake reply** (no API key configured, so nothing was sent to OpenAI).

        You said: *"{$last}"*

        I can see **{$turns} message(s)** of history, which proves conversation memory is wired up — the model would receive all of them.

        ```php
        // Set OPENAI_API_KEY in .env to get real answers.
        \$reply = app(\App\Services\OpenAIService::class)->reply(\$history);
        ```
        MARKDOWN;
    }

    /**
     * The fake stream deliberately yields word by word, so the SSE plumbing
     * can be developed and debugged exactly as if OpenAI were on the line.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return Generator<int, string>
     */
    protected function fakeStream(array $history): Generator
    {
        foreach (preg_split('/(\s+)/', $this->fakeReply($history), flags: PREG_SPLIT_DELIM_CAPTURE) as $word) {
            usleep(25_000);

            yield $word;
        }
    }
}
