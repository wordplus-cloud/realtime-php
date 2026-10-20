<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · API demo</title>
    @vite('resources/css/shop.css')
</head>
<body>
    <main class="sign-in">
        <h1>API demo</h1>
        <p class="muted">Sign in as Ann, Ben or Cara. Use another browser, or a private window, for a second person.</p>
        <form method="post" action="/login">
            @csrf
            @foreach ($people as $person)
                <button name="as" value="{{ $person->id }}"><img class="avatar" src="{{ $person->avatar }}" alt="" width="28" height="28"> {{ $person->name }} <small>{{ $person->role }}</small></button>
            @endforeach
        </form>
    </main>
</body>
</html>
