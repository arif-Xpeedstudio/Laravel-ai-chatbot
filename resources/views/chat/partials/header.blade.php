<header class="header">

    <h1 class="title">{{ $session?->displayTitle() ?? 'Laravel AI Chat' }}</h1>

    @if ($session)
        <div class="actions">
            {{-- Rename: hidden until the button is pressed (js/chat.js). --}}
            <button class="btn btn-ghost" data-toggle="#rename">Rename</button>

            <form method="POST" action="{{ route('chat.destroy', $session) }}" data-confirm="Delete this chat?">
                @csrf
                @method('DELETE')
                <button class="btn btn-ghost btn-danger">Delete</button>
            </form>
        </div>
    @endif
</header>

@if ($session)
    <form method="POST" action="{{ route('chat.update', $session) }}" class="rename hidden" id="rename">
        @csrf
        @method('PATCH')
        <input type="text" name="title" value="{{ old('title', $session->title) }}" maxlength="60" placeholder="Chat name">
        <button class="btn">Save</button>
    </form>
@endif

{{-- Flash messages and validation errors --}}
@if (session('success'))
    <p class="flash success">{{ session('success') }}</p>
@endif

@if (session('error'))
    <p class="flash error">{{ session('error') }}</p>
@endif

@error('message')
    <p class="flash error">{{ $message }}</p>
@enderror

@error('title')
    <p class="flash error">{{ $message }}</p>
@enderror
