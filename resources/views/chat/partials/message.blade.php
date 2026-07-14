@php
    /** @var \App\Models\Message $message */
@endphp

<div class="message {{ $message->isUser() ? 'user' : 'assistant' }}">

    <div class="avatar">{{ $message->isUser() ? 'You' : 'AI' }}</div>

    <div class="bubble">
        @if ($message->isUser())
            {{--
                User text is NEVER treated as markup. {{ }} escapes it, and
                nl2br only turns the newlines it produced into <br>. This is the
                line that stops someone pasting <script> into the chat box.
            --}}
            <div class="prose">{!! nl2br(e($message->content)) !!}</div>
        @else
            {{--
                The assistant is allowed markdown (headings, lists, code blocks),
                but 'html_input' => 'escape' means any raw HTML the model emits is
                shown as text rather than rendered. Never trust model output either.
            --}}
            <div class="prose">
                {!! Str::markdown($message->content, [
                    'html_input' => 'escape',
                    'allow_unsafe_links' => false,
                ]) !!}
            </div>
        @endif
    </div>
</div>
