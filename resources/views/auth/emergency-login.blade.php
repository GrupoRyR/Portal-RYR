{{-- Placeholder emergency sign-in (step 1); not linked from anywhere. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso de emergencia · {{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>Acceso de emergencia</h1>

        @error('emergency')
            <p role="alert">{{ $message }}</p>
        @enderror

        <form method="POST" action="{{ route('emergency.credentials') }}">
            @csrf
            <label>Correo <input type="email" name="email" autocomplete="username" required></label>
            <label>Contraseña <input type="password" name="password" autocomplete="current-password" required></label>
            <button type="submit">Continuar</button>
        </form>
    </main>
</body>
</html>
