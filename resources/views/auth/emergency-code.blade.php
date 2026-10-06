{{-- Placeholder emergency sign-in (step 2). --}}
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

        <form method="POST" action="{{ route('emergency.verify') }}">
            @csrf
            <label>Código de verificación
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
            </label>
            <button type="submit">Entrar</button>
        </form>
    </main>
</body>
</html>
