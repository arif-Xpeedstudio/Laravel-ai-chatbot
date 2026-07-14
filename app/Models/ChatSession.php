<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = [
        'title',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
