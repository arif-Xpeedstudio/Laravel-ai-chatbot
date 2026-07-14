<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | Which OpenAI chat model to send conversations to. Kept in config (not
    | hard-coded in the service) so it can be swapped from .env alone.
    |
    */

    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Generation settings
    |--------------------------------------------------------------------------
    */

    'temperature' => (float) env('OPENAI_TEMPERATURE', 0.7),
    'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1000),

    /*
    |--------------------------------------------------------------------------
    | System prompt
    |--------------------------------------------------------------------------
    |
    | Prepended to every conversation as the "system" message. This is how the
    | assistant's persona and rules are set.
    |
    */

    'system_prompt' => env('OPENAI_SYSTEM_PROMPT', 'You are a helpful assistant inside a Laravel chat app. Answer clearly and use Markdown for formatting. Use fenced code blocks with a language tag for any code.'),

    /*
    |--------------------------------------------------------------------------
    | Conversation memory
    |--------------------------------------------------------------------------
    |
    | How many past messages of a session are replayed to the model. The model
    | itself is stateless: "memory" is nothing more than us re-sending history
    | on every call. Larger = better memory, but more tokens and more cost.
    |
    */

    'history_limit' => (int) env('OPENAI_HISTORY_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | Fake mode
    |--------------------------------------------------------------------------
    |
    | With CHAT_FAKE=true the service never calls OpenAI; it returns a canned
    | reply instead. This lets the whole app (including streaming) be run and
    | learned from without an API key or spending any money.
    |
    | It is forced on whenever no API key is configured, so a missing key
    | degrades into a demo instead of a 401 error.
    |
    */

    'fake' => env('CHAT_FAKE', false) || blank(env('OPENAI_API_KEY')),

];
