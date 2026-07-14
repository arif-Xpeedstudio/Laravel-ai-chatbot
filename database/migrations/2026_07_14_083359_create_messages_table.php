<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // Deleting a session deletes its messages with it.
            $table->foreignId('chat_session_id')
                ->constrained()
                ->cascadeOnDelete();

            // The three roles the OpenAI chat API understands.
            $table->enum('role', ['system', 'user', 'assistant']);

            $table->text('content');
            $table->timestamps();

            // Every read is "this session's messages, oldest first".
            $table->index(['chat_session_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
