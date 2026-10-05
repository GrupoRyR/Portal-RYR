<?php

use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [MicrosoftLoginController::class, 'show'])->name('login');

    Route::middleware('throttle:20,1')->group(function () {
        Route::get('/auth/microsoft/redirect', [MicrosoftLoginController::class, 'redirect'])
            ->name('auth.microsoft.redirect');
        Route::get('/auth/microsoft/callback', [MicrosoftLoginController::class, 'callback'])
            ->name('auth.microsoft.callback');
    });

    // Emergency local sign-in (password + TOTP) for when Microsoft is down.
    // Unlinked; throttled inside App\Auth\EmergencyLogin so that lockouts
    // stay indistinguishable from any other failure.
    Route::prefix(config('portal.emergency_path'))->name('emergency.')->group(function () {
        Route::get('/', [EmergencyLoginController::class, 'show'])->name('login');
        Route::post('/', [EmergencyLoginController::class, 'credentials'])->name('credentials');
        Route::get('/codigo', [EmergencyLoginController::class, 'showCode'])->name('code');
        Route::post('/codigo', [EmergencyLoginController::class, 'code'])->name('verify');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('home'))->name('home');

    Route::post('/logout', LogoutController::class)->middleware('throttle:20,1')->name('logout');
});
