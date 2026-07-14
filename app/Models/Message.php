<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    /**
     * The roles the OpenAI chat API understands. Constants instead of loose
     * strings, so a typo like 'assistent' fails loudly at the call site.
     */
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'chat_session_id',
        'role',
        'content',
    ];

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }
}
