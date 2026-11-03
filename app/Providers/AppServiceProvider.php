<?php

namespace App\Providers;

use App\Backups\RecordBackupOutcome;
use App\Calendar\ColombianHolidays;
use App\Http\Controllers\Mcp\AuthorizeController;
use App\Mail\GraphAccessToken;
use App\Mail\GraphTransport;
use App\Microsoft\GraphClient;
use App\Microsoft\HttpGraphClient;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use App\Policies\AdminSections;
use App\Policies\RolePolicy;
use App\Pwa\PortalUserKey;
use App\Settings\ApplySessionLifetime;
use App\Settings\PortalSettings;
use App\Tenancy\TenantDirectory;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantDirectory::class);
        $this->app->singleton(PortalSettings::class);
        $this->app->bind(GraphClient::class, HttpGraphClient::class);
        // Holiday days are read in the portal's timezone.
        $this->app->singleton(ColombianHolidays::class, fn () => new ColombianHolidays(config('app.timezone')));

        // Claude connector: routes/ai.php declares only the Passport routes
        // it needs. The consent screen (a subclass of Passport's) signs in
        // with the portal's session guard, like Passport's own.
        Passport::ignoreRoutes();
        $this->app->when(AuthorizeController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard('web'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // HTTPS is mandatory in production: generated URLs, redirects and
        // asset links must never fall back to plain http.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Admin sections without a model of their own, and the roles page
        // (spatie's Role model lives outside app/, so no auto-discovery).
        Gate::define(AdminSections::ACTIVITY, [AdminSections::class, 'viewActivity']);
        Gate::define(AdminSections::INTEGRATIONS, [AdminSections::class, 'manageIntegrations']);
        Gate::define(AdminSections::SETTINGS, [AdminSections::class, 'manageSettings']);
        Gate::policy(Role::class, RolePolicy::class);

        // API for external systems: each token has its own budget; calls
        // that reach the limiter without a token are limited per address.
        RateLimiter::for('api', function (Request $request) {
            $integration = $request->user();

            return $integration instanceof Integration
                ? Limit::perMinute(Integration::REQUESTS_PER_MINUTE)->by('integration:'.$integration->getKey())
                : Limit::perMinute(30)->by('ip:'.$request->ip());
        });

        // «Confirmar lectura» (announcements.read): 30 a minute per account,
        // in a bucket of its own. `throttle:N,1` keys every route by the
        // account alone, so they would share one counter with it.
        RateLimiter::for('announcement-read', fn (Request $request) => Limit::perMinute(30)
            ->by('announcement-read:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->configureClaudeConnector();

        // Idle timeout of Configuración, applied before the session starts.
        Event::listen(RouteMatched::class, ApplySessionLifetime::class);

        // Every web response names its user for the service worker, which
        // drops a saved page as soon as someone else (or nobody) is signed in.
        Event::listen(RequestHandled::class, PortalUserKey::class);

        // Outcome of every backup, for the «Respaldo diario» card.
        Event::listen([BackupWasSuccessful::class, BackupHasFailed::class], RecordBackupOutcome::class);

        // `MAIL_MAILER=graph`: mail through Microsoft Graph sendMail
        // (config/mail.php, docs/despliegue.md §1.10). Missing settings are
        // reported when a message is sent, so callers' error handling and
        // logs still apply.
        Mail::extend('graph', function (array $config) {
            $settings = [
                'MICROSOFT_MAIL_TENANT_ID' => (string) ($config['tenant'] ?? ''),
                'MICROSOFT_CLIENT_ID' => (string) ($config['client_id'] ?? ''),
                'MICROSOFT_CLIENT_SECRET' => (string) ($config['client_secret'] ?? ''),
            ];
            $timeout = (int) ($config['timeout'] ?? 15);
            $http = $this->app->make(HttpFactory::class);

            return new GraphTransport(
                $http,
                new GraphAccessToken(
                    $http,
                    $this->app->make('cache.store'),
                    $settings['MICROSOFT_MAIL_TENANT_ID'],
                    $settings['MICROSOFT_CLIENT_ID'],
                    $settings['MICROSOFT_CLIENT_SECRET'],
                    $timeout,
                ),
                array_keys(array_filter($settings, fn (string $value) => trim($value) === '')),
                $timeout,
            );
        });

        // Laravel 11+ registration of the SocialiteProviders Microsoft driver.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });

        // The neutral sign-in page names the active companies of the group;
        // the colors give each dark logo its own company's chip. The support
        // URL is linked directly: the /soporte route needs a session.
        View::composer('auth.login', function (\Illuminate\View\View $view) {
            $view->with('companies', Company::active()->orderBy('name')->get([
                'name', 'short_name', 'logo_light_path', 'logo_dark_path', 'icon_path',
                'color_primary', 'color_accent', 'color_dark', 'color_button', 'color_ink',
                'color_text', 'color_ink_dark_mode', 'color_text_dark_mode',
            ]));
            $view->with('supportUrl', app(PortalSettings::class)->supportUrl());
        });
    }

    /**
     * Passport and the limits of the Claude connector (routes/ai.php,
     * odd/tasks/conector-mcp.md). The signing keys come from
     * PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY, or from the files of
     * `php artisan passport:keys` (docs/despliegue.md).
     */
    private function configureClaudeConnector(): void
    {
        // Short access tokens; refresh tokens rotate on every use
        // (Passport's default, kept) and last 30 days, not a year.
        Passport::tokensExpireIn(new DateInterval('PT1H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));
        Passport::$revokeRefreshTokenAfterUse = true;

        // The portal's own consent screen (resources/views/mcp).
        Passport::authorizationView('mcp.authorize');

        // Discovery and client registration are public. Claude calls them
        // from its own servers, so discovery gets more room than
        // registration, which writes a client row each time.
        RateLimiter::for('mcp-oauth', fn (Request $request) => $request->isMethod('POST')
            ? Limit::perMinute(10)->by('mcp-register:'.$request->ip())
            : Limit::perMinute(60)->by('mcp-discovery:'.$request->ip()));

        // MCP calls: a budget per connection (account and OAuth client).
        RateLimiter::for('mcp', function (Request $request) {
            $user = $request->user();

            return $user instanceof User
                ? Limit::perMinute(120)->by('mcp:'.$user->getKey().':'.$user->token()?->oauth_client_id)
                : Limit::perMinute(30)->by('mcp-ip:'.$request->ip());
        });
    }
}
