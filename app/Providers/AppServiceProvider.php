<?php

namespace App\Providers;

use App\Backups\RecordBackupOutcome;
use App\Calendar\ColombianHolidays;
use App\Mail\GraphAccessToken;
use App\Mail\GraphTransport;
use App\Microsoft\GraphClient;
use App\Microsoft\HttpGraphClient;
use App\Models\Company;
use App\Models\Integration;
use App\Policies\AdminSections;
use App\Policies\RolePolicy;
use App\Pwa\PortalUserKey;
use App\Settings\ApplySessionLifetime;
use App\Settings\PortalSettings;
use App\Tenancy\TenantDirectory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
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
}
