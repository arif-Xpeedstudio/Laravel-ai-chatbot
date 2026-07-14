<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Laravel AI Chat')</title>

    {{-- Plain CSS/JS from public/: no Vite build needed to run this project. --}}
    <link rel="stylesheet" href="{{ asset('css/chat.css') }}">
</head>
<body>

    @yield('content')

    <script src="{{ asset('js/chat.js') }}"></script>
</body>
</html>
