<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $fillable = [
        'title',
    ];

    /**
     * A session is a container for an ordered list of messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The last message in the session, used to decide whether we still owe
     * the user an assistant reply.
     */
    public function lastMessage(): ?Message
    {
        return $this->messages()->latest('id')->first();
    }

    /**
     * True when the newest message came from the user, i.e. the assistant has
     * not answered yet. The streaming endpoint refuses to run otherwise, so a
     * page refresh can never generate a second reply.
     */
    public function awaitsReply(): bool
    {
        return $this->lastMessage()?->role === Message::ROLE_USER;
    }

    /**
     * What the sidebar shows before a title has been generated.
     */
    public function displayTitle(): string
    {
        return $this->title ?: 'New chat';
    }

    /**
     * Sessions that match a sidebar search: by title, or by anything said
     * inside the conversation.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('title', 'like', "%{$term}%")
                ->orWhereHas('messages', fn (Builder $q) => $q->where('content', 'like', "%{$term}%"));
        });
    }
}
