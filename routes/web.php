<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/chat');

/*
| Rate limiting: every route that can reach OpenAI sits behind a throttle, so
| a stuck loop (or someone hammering the form) cannot run up an API bill.
*/
Route::middleware('throttle:30,1')->group(function () {
    // Reading the conversation list / a conversation.
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{session}', [ChatController::class, 'show'])->name('chat.show');

    // Sending a message: without a session id we start a new conversation.
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::post('/chat/{session}/messages', [ChatController::class, 'store'])->name('chat.messages.store');

    // The reply itself. The browser uses the SSE stream; the POST is the
    // fallback for when JavaScript is unavailable.
    Route::get('/chat/{session}/stream', [ChatController::class, 'stream'])->name('chat.stream');
    Route::post('/chat/{session}/reply', [ChatController::class, 'reply'])->name('chat.reply');

    // Managing conversations.
    Route::patch('/chat/{session}', [ChatController::class, 'update'])->name('chat.update');
    Route::delete('/chat/{session}', [ChatController::class, 'destroy'])->name('chat.destroy');
});
