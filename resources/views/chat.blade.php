<!DOCTYPE html>
<html>
<head>
    <title>Laravel AI Chat</title>
</head>
<body>

    <h1>Laravel AI Chatbot</h1>

    <form method="POST" action="{{ route('chat.store') }}">
        @csrf

       <textarea name="message"placeholder="Type your message"rows="4"cols="50"></textarea>

        <button>
            Send
        </button>

        @if(isset($message))
            <p><strong>Message:</strong> {{ $message }}</p>
        @endif

    </form>

</body>
</html>