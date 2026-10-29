<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\LinkedAccountController;
use App\Http\Controllers\Account\SwitchAccountController;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\AppController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CompanyWizardController;
use App\Http\Controllers\Admin\UsageController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AnnouncementReadController;
use App\Http\Controllers\AppLaunchController;
use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\EventCalendarController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use App\Http\Controllers\SupportController;
use App\Models\Announcement;
use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use App\Models\Event;
use App\Policies\AdminSections;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

// Installable app (PWA): the manifest of the signed-in user's company,
// the neutral portal one for guests, and the service worker at the root
// so that its scope is the whole portal.
Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.service-worker');

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

    // «Cuentas vinculadas»: the person's accounts. Unlinking one is a
    // confirmation page and a POST, both behind a fresh Microsoft
    // confirmation that the controller checks itself (a 404, not the 403
    // of microsoft.recent, for emergency accounts).
    Route::get('/cuenta', [AccountController::class, 'show'])->name('account.show');
    Route::middleware('throttle:20,1')->group(function () {
        Route::get('/cuenta/desvincular/{user}', [AccountController::class, 'confirmUnlink'])
            ->whereNumber('user')
            ->name('account.unlink.confirm');
        Route::post('/cuenta/desvincular/{user}', [AccountController::class, 'unlink'])
            ->whereNumber('user')
            ->name('account.unlink');
    });

    // Linking another company's account (App\Auth\LinkedAccounts): a fresh
    // Microsoft confirmation first, then a sign-in to the other account.
    // microsoft.recent only guards GET; the POST checks it itself.
    Route::get('/cuenta/vincular', [LinkedAccountController::class, 'create'])
        ->middleware('microsoft.recent')
        ->name('account.link');
    Route::post('/cuenta/vincular', [LinkedAccountController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('account.link.start');

    // Switching to another linked account: a real Microsoft sign-in with
    // that account (App\Http\Controllers\Account\SwitchAccountController).
    Route::post('/cuenta/cambiar/{user}', [SwitchAccountController::class, 'store'])
        ->whereNumber('user')
        ->middleware('throttle:20,1')
        ->name('account.switch');

    // Every announcement the user may see (the home shows the latest ones).
    Route::get('/anuncios', [AnnouncementController::class, 'index'])->name('announcements.index');
    // One of them, on its page of the list (the «Ctrl K» search links here).
    Route::get('/anuncios/{announcement}', [AnnouncementController::class, 'show'])
        ->whereNumber('announcement')
        ->middleware('throttle:60,1')
        ->name('announcements.show');
    // «Confirmar lectura» of a mandatory announcement (once per account).
    // Its own limiter (AppServiceProvider): `throttle:N,1` routes share one
    // counter per account, so other pages could use up its budget.
    Route::post('/anuncios/{announcement}/leido', [AnnouncementReadController::class, 'store'])
        ->whereNumber('announcement')
        ->middleware('throttle:announcement-read')
        ->name('announcements.read');

    // Company events the user may see (App\Models\Event::scopeVisibleTo),
    // soonest first, and «Agregar a mi calendario» of one of them: an .ics
    // file, or a 404 when the user cannot see it right now.
    Route::get('/eventos', [EventController::class, 'index'])->name('events.index');
    Route::get('/eventos/{event}.ics', EventCalendarController::class)
        ->whereNumber('event')
        ->middleware('throttle:60,1')
        ->name('events.calendar');

    // Catalog (App\Catalog\AppCatalog decides access; no access is a 404).
    Route::get('/apps/{app:slug}', AppLaunchController::class)
        ->middleware('throttle:60,1')
        ->name('apps.open');

    // Soporte TI: redirect to the ticket platform of Configuración with
    // where the person comes from (404 when no support URL is set).
    Route::get('/soporte', SupportController::class)
        ->middleware('throttle:60,1')
        ->name('support');

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

        // App\Policies\EventPolicy: the same people as announcements.
        // App\Livewire\Admin\EventList and EventEditor re-authorize every
        // action. {event} is bound in AppServiceProvider (Route::model).
        Route::view('/eventos', 'admin.events.index')
            ->can('viewAny', Event::class)
            ->name('events.index');
        Route::view('/eventos/nuevo', 'admin.events.edit', ['event' => null])
            ->can('create', Event::class)
            ->name('events.create');
        Route::view('/eventos/{event}', 'admin.events.edit')
            ->whereNumber('event')
            ->can('update', 'event')
            ->name('events.edit');

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

        // App\Policies\AdminSections::ACTIVITY (Auditoría and Informe de uso):
        // super_admin and portal_admin.
        Route::middleware('can:'.AdminSections::ACTIVITY)->group(function () {
            Route::get('/auditoria', [AuditController::class, 'index'])->name('audit.index');
            Route::get('/auditoria/exportar', [AuditController::class, 'export'])
                ->middleware('throttle:10,1')
                ->name('audit.export');
            Route::get('/informe-uso', UsageController::class)->name('usage.index');
        });

        // App\Policies\AdminSections::SETTINGS (Configuración): super_admin
        // only; App\Livewire\Admin\SettingsEditor re-authorizes each action.
        Route::view('/configuracion', 'admin.settings.index')
            ->middleware('can:'.AdminSections::SETTINGS)
            ->name('settings.index');

        // App\Policies\AdminSections::INTEGRATIONS (Integraciones, API tokens):
        // super_admin only; App\Livewire\Admin\IntegrationManager
        // re-authorizes each action.
        Route::view('/integraciones', 'admin.integrations.index')
            ->middleware('can:'.AdminSections::INTEGRATIONS)
            ->name('integrations.index');

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
