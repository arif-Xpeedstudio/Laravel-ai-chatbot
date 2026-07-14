@extends('layouts.app')

@section('title', $session?->displayTitle() ?? 'Laravel AI Chat')

@section('content')
<div class="app">

    @include('chat.partials.sidebar')

    <main class="main">

        @include('chat.partials.header')

        <div class="messages" id="messages">

            @if (! $session || $session->messages->isEmpty())
                @include('chat.partials.empty')
            @else
                @foreach ($session->messages as $message)
                    @include('chat.partials.message', ['message' => $message])
                @endforeach
            @endif

            {{--
                The session is waiting for an answer: the newest message is the
                user's. We render an empty assistant bubble and let JavaScript
                fill it from the SSE stream (see public/js/chat.js).
            --}}
            @if ($session?->awaitsReply())
                <div class="message assistant" id="pending" data-stream-url="{{ route('chat.stream', $session) }}">
                    <div class="avatar">AI</div>
                    <div class="bubble">
                        <div class="prose" id="pending-text"></div>
                        <div class="typing" id="typing"><span></span><span></span><span></span></div>
                    </div>
                </div>

                {{-- No JavaScript? Then ask for the reply the plain, blocking way. --}}
                <noscript>
                    <form method="POST" action="{{ route('chat.reply', $session) }}" class="fallback">
                        @csrf
                        <button class="btn">Generate reply</button>
                    </form>
                </noscript>
            @endif
        </div>

        @include('chat.partials.composer')

    </main>
</div>
@endsection
