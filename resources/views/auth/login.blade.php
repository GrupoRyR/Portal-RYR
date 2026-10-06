{{-- Placeholder sign-in page; the designed screen comes with the UI foundation. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · {{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>{{ config('app.name') }}</h1>

        @error('login')
            <p role="alert">{{ $message }}</p>
        @enderror

        <a href="{{ route('auth.microsoft.redirect') }}">Entrar con Microsoft</a>
    </main>
</body>
</html>
