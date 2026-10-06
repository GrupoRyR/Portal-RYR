<?php

use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AppController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CompanyWizardController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AppLaunchController;
use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HomeController;
use App\Models\Announcement;
use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

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

    // Every announcement the user may see (the home shows the latest ones).
    Route::get('/anuncios', [AnnouncementController::class, 'index'])->name('announcements.index');

    // Catalog (App\Catalog\AppCatalog decides access; no access is a 404).
    Route::get('/apps/{app:slug}', AppLaunchController::class)
        ->middleware('throttle:60,1')
        ->name('apps.open');

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/favoritos/{app:slug}', [FavoriteController::class, 'store'])->name('favorites.store');
        Route::delete('/favoritos/{app:slug}', [FavoriteController::class, 'destroy'])->name('favorites.destroy');
    });
});

// Administration: super_admin, portal_admin and announcements_editor (role
// names from Database\Seeders\RoleSeeder). Each section narrows access
// further; announcements editors only reach the announcements.
Route::middleware(['auth', 'role:super_admin|portal_admin|announcements_editor'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Each role lands on the first section it may use: companies for
        // the super admin, the app catalog for portal admins, announcements
        // for announcements editors.
        Route::get('/', fn () => redirect()->route(match (true) {
            Gate::allows('viewAny', Company::class) => 'admin.companies.index',
            Gate::allows('viewAny', App::class) => 'admin.apps.index',
            default => 'admin.announcements.index',
        }))->name('index');

        // App\Policies\AnnouncementPolicy: admins any announcement,
        // announcements editors only their own company's.
        Route::get('/anuncios', [AdminAnnouncementController::class, 'index'])
            ->can('viewAny', Announcement::class)
            ->name('announcements.index');
        Route::get('/anuncios/nuevo', [AdminAnnouncementController::class, 'create'])
            ->can('create', Announcement::class)
            ->name('announcements.create');
        Route::get('/anuncios/{announcement}', [AdminAnnouncementController::class, 'edit'])
            ->whereNumber('announcement')
            ->can('update', 'announcement')
            ->name('announcements.edit');

        // App\Policies\AppPolicy: super_admin and portal_admin.
        Route::get('/aplicaciones', [AppController::class, 'index'])
            ->can('viewAny', App::class)
            ->name('apps.index');
        Route::get('/aplicaciones/nueva', [AppController::class, 'create'])
            ->can('create', App::class)
            ->name('apps.create');
        Route::get('/aplicaciones/{app:slug}', [AppController::class, 'edit'])
            ->can('update', 'app')
            ->name('apps.edit');

        // App\Policies\AppCategoryPolicy: super_admin and portal_admin.
        Route::view('/categorias', 'admin.categories.index')
            ->can('viewAny', AppCategory::class)
            ->name('categories.index');

        // App\Policies\RolePolicy: super_admin and portal_admin; both only
        // add or remove announcements editors.
        Route::view('/roles', 'admin.roles.index')
            ->can('viewAny', Role::class)
            ->name('roles.index');

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
