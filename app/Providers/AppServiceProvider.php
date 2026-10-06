<?php

namespace App\Providers;

use App\Microsoft\GraphClient;
use App\Microsoft\HttpGraphClient;
use App\Models\Company;
use App\Policies\AdminSections;
use App\Policies\RolePolicy;
use App\Tenancy\TenantDirectory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantDirectory::class);
        $this->app->bind(GraphClient::class, HttpGraphClient::class);
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

        // Laravel 11+ registration of the SocialiteProviders Microsoft driver.
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });

        // The neutral sign-in page names the active companies of the group.
        View::composer('auth.login', function (\Illuminate\View\View $view) {
            $view->with('companies', Company::active()->orderBy('name')->get(['name', 'short_name', 'logo_light_path', 'logo_dark_path']));
        });
    }
}
