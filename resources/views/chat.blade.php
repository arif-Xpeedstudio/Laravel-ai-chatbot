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

        @error('message')
    <p style="color:red">{{ $message }}</p>
@enderror

        @if(isset($foo) && isset($loo))
            <p><strong>Message :</strong> {{ $foo }}</p>
            <p><strong>Length:</strong> {{ $loo }}</p>
        @endif


    </form>
    
</body>
</html>