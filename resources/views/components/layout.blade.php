<!DOCTYPE html>
<html>

<head>
    <title>{{ $title ?? 'Default Title' }}</title>
</head>

<body>

    <nav>
        <div><a href="{{ route('show.register') }}">register</a></div>
        <div><a href="{{ route('show.login') }}">login</a></div>
    </nav>


    <main>
        {{ $slot }}
    </main>
</body>

</html>
