<aside class="sidebar">

    <a href="{{ route('chat.index') }}" class="btn btn-new">
        <span>+</span> New chat
    </a>

    {{-- Search is a plain GET form: the query lives in the URL (?q=...), so it
         survives a refresh and can be bookmarked. --}}
    <form method="GET" action="{{ route('chat.index') }}" class="search">
        <input
            type="search"
            name="q"
            value="{{ $search }}"
            placeholder="Search chats..."
            aria-label="Search chats"
        >
    </form>

    <nav class="sessions">
        @forelse ($sessions as $item)
            <a
                href="{{ route('chat.show', $item) }}"
                class="session {{ $session?->is($item) ? 'active' : '' }}"
                title="{{ $item->displayTitle() }}"
            >
                {{ $item->displayTitle() }}
            </a>
        @empty
            <p class="muted">
                {{ filled($search) ? 'No chat matches your search.' : 'No chats yet.' }}
            </p>
        @endforelse
    </nav>

    <footer class="sidebar-footer">
        @if (config('chat.fake'))
            <span class="badge" title="Set OPENAI_API_KEY in .env to call the real API">demo mode</span>
        @else
            <span class="muted">{{ config('chat.model') }}</span>
        @endif
    </footer>
</aside>
