{{--
    One form for both cases: with an open session we post into it, otherwise we
    post to /chat and the controller starts a new session for us.
--}}
<form
    method="POST"
    action="{{ $session ? route('chat.messages.store', $session) : route('chat.store') }}"
    class="composer"
    id="composer"
>
    @csrf

    <textarea
        name="message"
        id="message"
        rows="1"
        placeholder="Send a message...  (Enter to send, Shift+Enter for a new line)"
        autofocus
    >{{ old('message') }}</textarea>

    <button class="btn btn-send" id="send">Send</button>
</form>
