<div class="empty">
    <h2>Laravel AI Chatbot</h2>
    <p class="muted">Ask anything. Your conversation is saved and the AI remembers it.</p>

    @if (config('chat.fake'))
        <p class="badge-lg">
            Demo mode — no <code>OPENAI_API_KEY</code> set, so replies are faked.
            Everything else (saving, history, streaming) is real.
        </p>
    @endif
</div>
