<?php

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
});

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('home'))->name('home');

    Route::post('/logout', LogoutController::class)->middleware('throttle:20,1')->name('logout');
});
