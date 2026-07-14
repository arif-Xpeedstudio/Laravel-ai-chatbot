<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * One exception type for "talking to the AI failed".
 *
 * The point is that the rest of the app never has to know what an
 * OpenAI\Exceptions\ErrorException is. The service catches whatever the SDK
 * throws and re-throws this, carrying a message that is safe to show a user.
 */
class ChatServiceException extends RuntimeException
{
    public static function fromApi(Throwable $previous): self
    {
        return new self(
            'The AI could not answer right now. Please try again in a moment.',
            previous: $previous,
        );
    }

    public static function missingApiKey(): self
    {
        return new self('No OpenAI API key is configured. Add OPENAI_API_KEY to your .env file.');
    }
}
