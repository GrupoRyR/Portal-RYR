<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CompanyWizardController;
use App\Http\Controllers\AppLaunchController;
use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HomeController;
use App\Models\Company;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [MicrosoftLoginController::class, 'show'])->name('login');

    Route::middleware('throttle:20,1')->group(function () {
        Route::get('/auth/microsoft/redirect', [MicrosoftLoginController::class, 'redirect'])
            ->name('auth.microsoft.redirect');
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

// Shared by sign-in (guests) and re-authentication (signed-in users).
Route::get('/auth/microsoft/callback', [MicrosoftLoginController::class, 'callback'])
    ->middleware('throttle:20,1')
    ->name('auth.microsoft.callback');

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    // Re-authentication before sensitive actions (middleware microsoft.recent).
    Route::get('/auth/microsoft/confirm', [MicrosoftLoginController::class, 'confirm'])
        ->middleware('throttle:20,1')
        ->name('auth.microsoft.confirm');

    Route::post('/logout', LogoutController::class)->middleware('throttle:20,1')->name('logout');

    // Catalog (App\Catalog\AppCatalog decides access; no access is a 404).
    Route::get('/apps/{app:slug}', AppLaunchController::class)
        ->middleware('throttle:60,1')
        ->name('apps.open');

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/favoritos/{app:slug}', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/favoritos/{app:slug}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');
    });
});

// Administration: super_admin and portal_admin only (role names from
// Database\Seeders\RoleSeeder). Each section narrows access further.
Route::middleware(['auth', 'role:super_admin|portal_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', fn () => redirect()->route('admin.companies.index'))->name('index');

        // App\Policies\CompanyPolicy: super_admin only (portal_admin cannot
        // manage companies, decision 2026-10-05).
        Route::get('/empresas', [CompanyController::class, 'index'])
            ->can('viewAny', Company::class)
            ->name('companies.index');
        // Creating a company opens the portal to a whole Microsoft
        // organization: super_admin plus a fresh Microsoft re-authentication.
        Route::middleware('microsoft.recent')->group(function () {
            Route::get('/empresas/nueva', [CompanyWizardController::class, 'create'])
                ->can('create', Company::class)
                ->name('companies.create');
            Route::get('/empresas/{company:slug}/asistente', [CompanyWizardController::class, 'resume'])
                ->can('update', 'company')
                ->name('companies.wizard');
        });
        Route::get('/empresas/{company:slug}', [CompanyController::class, 'show'])
            ->can('update', 'company')
            ->name('companies.show');
    });
