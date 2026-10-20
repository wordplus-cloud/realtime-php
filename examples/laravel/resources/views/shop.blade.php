<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API demo</title>
    {{-- The signed-in user realtime settings, keys and identity included, with the CSRF token: window.wordplusRealtimeConfig --}}
    @wordplusRealtime
    <script type="application/json" id="shop">@json($shop)</script>
    @vite(['resources/css/shop.css', 'resources/js/shop.js'])
</head>
<body>
    <header class="shop-bar">
        <strong>API demo</strong>
        <form class="shop-me" method="post" action="{{ route('logout') }}">
            @csrf
            <img class="avatar" src="{{ $me->avatar }}" alt="" width="24" height="24"> {{ $me->name }}
            <button class="quiet" type="submit">Sign out</button>
        </form>
    </header>
    <main class="shop-main" id="shop-root"></main>
</body>
</html>
