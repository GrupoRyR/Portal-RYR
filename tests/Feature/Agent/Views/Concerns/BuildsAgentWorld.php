<?php

namespace Tests\Feature\Agent\Views\Concerns;

use App\Agent\Views\AgentViews;
use App\Models\Announcement;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\Event;
use App\Models\Integration;
use App\Models\ItService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A realistic mix of data for the agent views: two mapped companies
 * (segurex → SGX, derivados → SDC), one that is not in the mapping, and rows
 * at the edges of every rule. The views run with a fixed local «now» so the
 * Eloquent scopes (Carbon test now) and the views see the same instant.
 */
trait BuildsAgentWorld
{
    /** Security group of the apps that only a group opens (see seedItStatus). */
    protected const IT_STATUS_GROUP = 'APP-Contabilidad';

    protected Company $sgx;

    protected Company $sdc;

    protected Company $other;

    protected function makeCompanies(): void
    {
        config(['app.timezone' => 'America/Bogota']);

        $this->sgx = Company::factory()->create(['slug' => 'segurex', 'name' => 'Segurex Ejemplo S.A.S.', 'short_name' => 'Segurex']);
        $this->sdc = Company::factory()->create(['slug' => 'derivados', 'name' => 'Derivados Ejemplo S.A.S.', 'short_name' => 'Derivados']);
        $this->other = Company::factory()->create(['slug' => 'otra-empresa', 'name' => 'Otra Ejemplo S.A.S.', 'short_name' => 'Otra']);
    }

    /** Fixes «now» for Eloquent and for the views at a local (Bogotá) moment. */
    protected function freeze(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'America/Bogota'));
        AgentViews::install(DB::connection(), $local);
    }

    /** @return Collection<int, object> */
    protected function rows(string $view, string $empresa): Collection
    {
        return DB::table($view)->where('empresa', $empresa)->get();
    }

    /**
     * @param  Collection<int, int|string>|array<int, int|string>  $ids
     * @return list<int>
     */
    protected function sortedIds(Collection|array $ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    protected function microsoftUser(?Company $company, string $email, array $attributes = []): User
    {
        $user = User::factory()->microsoft($company ?? $this->other)->create(array_merge(['email' => $email, 'upn' => $email], $attributes));

        if ($company === null) {
            $user->forceFill(['company_id' => null])->save();
        }

        return $user->refresh();
    }

    /** The signed-in user of a company for the visibility scopes. */
    protected function userOf(Company $company): User
    {
        return $this->microsoftUser($company, 'visitante.'.$company->slug.'@example.test');
    }

    /**
     * @param  list<Company>  $companies
     * @param  array<string, mixed>  $attributes
     */
    protected function announcement(string $title, array $attributes = [], array $companies = []): Announcement
    {
        $factory = $companies === [] ? Announcement::factory() : Announcement::factory()->forCompanies(...$companies);

        return $factory->create(array_merge(['title' => $title, 'starts_at' => '2026-11-11 08:00:00'], $attributes));
    }

    /** Announcements around 2026-11-12 10:00:00. @return array<string, Announcement> */
    protected function seedAnnouncements(): array
    {
        return [
            'all' => $this->announcement('all'),
            'pinned-mandatory' => $this->announcement('pinned-mandatory', ['is_pinned' => true, 'requires_read' => true]),
            'sgx-only' => $this->announcement('sgx-only', [], [$this->sgx]),
            'sdc-only' => $this->announcement('sdc-only', [], [$this->sdc]),
            'both' => $this->announcement('both', [], [$this->sgx, $this->sdc]),
            'other-only' => $this->announcement('other-only', [], [$this->other]),
            'sgx-and-other' => $this->announcement('sgx-and-other', [], [$this->sgx, $this->other]),
            'companies-without-companies' => $this->announcement('companies-without-companies', ['audience' => Announcement::AUDIENCE_COMPANIES]),
            'scheduled' => $this->announcement('scheduled', ['starts_at' => '2026-11-13 08:00:00']),
            'starts-one-second-late' => $this->announcement('starts-one-second-late', ['starts_at' => '2026-11-12 10:00:01']),
            'starts-now' => $this->announcement('starts-now', ['starts_at' => '2026-11-12 10:00:00']),
            'utc-trap' => $this->announcement('utc-trap', ['starts_at' => '2026-11-12 15:00:00']),
            'expired' => $this->announcement('expired', ['starts_at' => '2026-11-01 08:00:00', 'ends_at' => '2026-11-11 08:00:00']),
            'ends-one-second-early' => $this->announcement('ends-one-second-early', ['ends_at' => '2026-11-12 09:59:59']),
            'ends-now' => $this->announcement('ends-now', ['ends_at' => '2026-11-12 10:00:00']),
            'ends-one-second-late' => $this->announcement('ends-one-second-late', ['ends_at' => '2026-11-12 10:00:01']),
            'ends-tomorrow' => $this->announcement('ends-tomorrow', ['ends_at' => '2026-11-13 10:00:00']),
            'withdrawn' => $this->announcement('withdrawn', ['withdrawn_at' => '2026-11-11 12:00:00']),
            'withdrawn-and-expired' => $this->announcement('withdrawn-and-expired', ['ends_at' => '2026-11-11 09:00:00', 'withdrawn_at' => '2026-11-10 12:00:00']),
        ];
    }

    /**
     * @param  list<Company>  $companies
     * @param  array<string, mixed>  $attributes
     */
    protected function event(string $title, array $attributes = [], array $companies = []): Event
    {
        $factory = $companies === [] ? Event::factory() : Event::factory()->forCompanies(...$companies);

        return $factory->create(array_merge(['title' => $title, 'published_at' => '2026-01-01 00:00:00', 'repeats_yearly' => false, 'all_day' => false, 'ends_at' => null], $attributes));
    }

    /** Events of every kind, seen from instants around 2026-11-12 10:00:00 and later years. @return array<string, Event> */
    protected function seedEvents(): array
    {
        $day = fn (string $first, ?string $last = null) => ['all_day' => true, 'starts_at' => $first.' 00:00:00', 'ends_at' => $last === null ? null : $last.' 00:00:00'];
        $yearly = fn (string $first, ?string $last = null) => $day($first, $last) + ['repeats_yearly' => true];

        return [
            'timed-future' => $this->event('timed-future', ['starts_at' => '2026-11-20 15:00:00', 'ends_at' => '2026-11-20 17:00:00']),
            'timed-over-today' => $this->event('timed-over-today', ['starts_at' => '2026-11-12 08:00:00', 'ends_at' => '2026-11-12 09:59:59']),
            'timed-ends-now' => $this->event('timed-ends-now', ['starts_at' => '2026-11-12 09:00:00', 'ends_at' => '2026-11-12 10:00:00']),
            'timed-ends-soon' => $this->event('timed-ends-soon', ['starts_at' => '2026-11-12 09:00:00', 'ends_at' => '2026-11-12 10:00:01']),
            'timed-no-end-today' => $this->event('timed-no-end-today', ['starts_at' => '2026-11-12 08:00:00']),
            'timed-no-end-midnight' => $this->event('timed-no-end-midnight', ['starts_at' => '2026-11-12 00:00:00']),
            'timed-no-end-yesterday' => $this->event('timed-no-end-yesterday', ['starts_at' => '2026-11-11 23:00:00']),
            'timed-no-end-tomorrow' => $this->event('timed-no-end-tomorrow', ['starts_at' => '2026-11-13 07:00:00']),
            'allday-today' => $this->event('allday-today', $day('2026-11-12')),
            'allday-yesterday' => $this->event('allday-yesterday', $day('2026-11-11')),
            'allday-multi-current' => $this->event('allday-multi-current', $day('2026-11-10', '2026-11-12')),
            'allday-multi-over' => $this->event('allday-multi-over', $day('2026-11-09', '2026-11-11')),
            'scheduled' => $this->event('scheduled', ['starts_at' => '2026-12-01 09:00:00', 'published_at' => '2026-11-13 08:00:00']),
            'published-one-second-late' => $this->event('published-one-second-late', ['starts_at' => '2026-12-01 09:00:00', 'published_at' => '2026-11-12 10:00:01']),
            'published-now' => $this->event('published-now', ['starts_at' => '2026-12-01 09:00:00', 'published_at' => '2026-11-12 10:00:00']),
            'withdrawn' => $this->event('withdrawn', ['starts_at' => '2026-12-01 09:00:00', 'withdrawn_at' => '2026-11-01 00:00:00']),
            'sgx-only' => $this->event('sgx-only', ['starts_at' => '2026-12-02 09:00:00'], [$this->sgx]),
            'sdc-only' => $this->event('sdc-only', ['starts_at' => '2026-12-02 09:00:00'], [$this->sdc]),
            'other-only' => $this->event('other-only', ['starts_at' => '2026-12-02 09:00:00'], [$this->other]),
            'both' => $this->event('both', ['starts_at' => '2026-12-02 09:00:00'], [$this->sgx, $this->sdc]),
            'yearly-march' => $this->event('yearly-march', $yearly('2020-03-15')),
            'yearly-december' => $this->event('yearly-december', $yearly('2020-12-01')),
            'yearly-leap-day' => $this->event('yearly-leap-day', $yearly('2024-02-29')),
            'yearly-new-year-span' => $this->event('yearly-new-year-span', $yearly('2020-12-30', '2021-01-03')),
            'yearly-leap-span' => $this->event('yearly-leap-span', $yearly('2024-02-28', '2024-03-01')),
            'yearly-first-in-future' => $this->event('yearly-first-in-future', $yearly('2030-05-05')),
            'yearly-today' => $this->event('yearly-today', $yearly('2026-11-12')),
            'yearly-last-day' => $this->event('yearly-last-day', $yearly('2025-12-31')),
            'yearly-not-all-day' => $this->event('yearly-not-all-day', ['starts_at' => '2020-03-15 10:00:00', 'ends_at' => '2020-03-15 11:00:00', 'repeats_yearly' => true]),
            'yearly-sgx-only' => $this->event('yearly-sgx-only', $yearly('2019-07-04'), [$this->sgx]),
            'yearly-other-only' => $this->event('yearly-other-only', $yearly('2019-07-04'), [$this->other]),
            'yearly-withdrawn' => $this->event('yearly-withdrawn', $yearly('2019-07-04') + ['withdrawn_at' => '2026-01-02 00:00:00']),
        ];
    }

    /**
     * Services and apps at 2026-11-12 10:00:00, with reports at the edges of
     * every Configuración window (1, 2 and 4 hours).
     *
     * @return array<string, App> the apps by slug
     */
    protected function seedItStatus(): array
    {
        $services = [
            [$this->sgx, 'VPN', 'operational', null],
            [$this->sgx, 'Correo', 'degraded', 'Llegan con retraso'],
            [$this->sgx, 'Internet', 'down', 'Sin enlace'],
            [$this->sgx, 'Telefonía', 'weird', 'Estado raro'],
            [$this->sdc, 'VPN', 'operational', null],
            [$this->sdc, 'Correo', 'down', 'Caído'],
            [$this->other, 'Correo', 'down', 'No debe salir'],
        ];

        foreach ($services as $position => [$company, $name, $status, $message]) {
            ItService::factory()->create(['company_id' => $company->id, 'name' => $name, 'status' => $status, 'message' => $message, 'position' => $position]);
        }

        $apps = [];
        // $rules: true = a «whole company» rule for each company that gets the
        // app; false = no rule at all; a closure = the rules the test wants.
        $app = function (string $slug, array $attributes, array $companies, bool|Closure $rules = true) use (&$apps): void {
            $factory = App::factory();
            $apps[$slug] = $factory->create(array_merge(['slug' => $slug, 'name' => $slug, 'url' => "https://{$slug}.segurex.com.co"], $attributes));
            $apps[$slug]->companies()->attach(collect($companies)->pluck('id'));

            if ($rules instanceof Closure) {
                $rules($apps[$slug]);
            } elseif ($rules) {
                $granted = ($attributes['available_to_all_companies'] ?? false) ? [$this->sgx, $this->sdc, $this->other] : $companies;
                foreach ($granted as $company) {
                    AppAccessRule::forCompany($apps[$slug], $company);
                }
            }
        };
        $report = fn (string $status, string $at) => ['external_status' => $status, 'external_status_message' => "{$status} {$at}", 'external_status_at' => $at];

        $app('healthy-all', ['available_to_all_companies' => true], []);
        $app('maintenance-all', ['available_to_all_companies' => true, 'status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Actualizando'], []);
        $app('down-sgx-only', $report('down', '2026-11-12 09:00:00'), [$this->sgx]);
        $app('degraded-both', $report('degraded', '2026-11-12 09:30:00'), [$this->sgx, $this->sdc]);
        $app('report-1h30', $report('down', '2026-11-12 08:30:00'), [$this->sgx, $this->sdc]);
        $app('report-just-inside', $report('degraded', '2026-11-12 08:00:01'), [$this->sgx]);
        $app('report-at-2h', $report('down', '2026-11-12 08:00:00'), [$this->sgx]);
        $app('report-3h', $report('down', '2026-11-12 07:00:00'), [$this->sgx, $this->sdc]);
        $app('report-4h', $report('degraded', '2026-11-12 06:00:00'), [$this->sdc]);
        $app('report-5h', $report('down', '2026-11-12 05:00:00'), [$this->sgx]);
        $app('report-operational', $report('operational', '2026-11-12 09:55:00'), [$this->sgx]);
        $app('report-unknown', $report('weird', '2026-11-12 09:55:00'), [$this->sgx]);
        $app('hidden-maintenance', ['status' => App::STATUS_HIDDEN, 'maintenance_message' => 'No'], [$this->sgx]);
        $app('maintenance-sdc', ['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Solo SDC'], [$this->sdc]);
        $app('maintenance-and-down', ['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Gana'] + $report('down', '2026-11-12 09:55:00'), [$this->sgx]);
        $app('maintenance-nobody', ['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Nadie'], []);
        $app('maintenance-other-only', ['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Otra'], [$this->other]);
        // The company gets them, but no «whole company» rule opens them for everybody: PHP decides
        // per person, the view (one row set per company) cannot, so it leaves them out.
        $app('down-without-rule', $report('down', '2026-11-12 09:55:00'), [$this->sgx], rules: false);
        $app('down-group-rule-only', $report('down', '2026-11-12 09:55:00'), [$this->sgx], rules: fn (App $app) => AppAccessRule::forGroup($app, $this->sgx, self::IT_STATUS_GROUP));
        $app('down-user-rule-only', $report('down', '2026-11-12 09:55:00'), [$this->sgx], rules: fn (App $app) => AppAccessRule::forUser($app, $this->microsoftUser($this->sgx, 'asignado.directo@example.test')));
        $app('maintenance-group-rule-only', ['status' => App::STATUS_MAINTENANCE, 'maintenance_message' => 'Solo grupo'], [$this->sgx], rules: fn (App $app) => AppAccessRule::forGroup($app, $this->sgx, self::IT_STATUS_GROUP));
        // Both companies get it, but only Derivados has the «whole company» rule.
        $app('down-rule-of-sdc-only', $report('down', '2026-11-12 09:55:00'), [$this->sgx, $this->sdc], rules: fn (App $app) => AppAccessRule::forCompany($app, $this->sdc));
        // A «whole company» rule filed under a company that is not linked to the app grants nothing.
        $app('down-rule-of-unlinked-company', $report('down', '2026-11-12 09:55:00'), [$this->sdc], rules: fn (App $app) => AppAccessRule::forCompany($app, $this->sgx));

        return $apps;
    }

    /** @return list<CompanyKpi> */
    protected function seedKpis(): array
    {
        $kpi = fn (Company $company, string $key, int $position, array $attributes = []) => CompanyKpi::factory()->create(array_merge([
            'company_id' => $company->id, 'key' => $key, 'position' => $position, 'label' => "Cifra {$key}", 'as_of' => '2026-11-11 18:30:00',
        ], $attributes));

        return [
            $kpi($this->sgx, 'ventas', 1, ['value' => 1250000000.5, 'goal' => 1500000000, 'unit' => 'COP', 'note' => 'Parcial']),
            $kpi($this->sgx, 'margen', 2, ['value' => 12.5, 'goal' => null, 'unit' => '%', 'note' => null]),
            $kpi($this->sdc, 'ventas', 1, ['value' => 90, 'goal' => 100, 'unit' => null]),
            $kpi($this->other, 'ventas', 1, ['value' => 1]),
        ];
    }

    /**
     * An integration of the tasks app that the portal may use.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function workIntegration(string $name, App $app, array $attributes = []): Integration
    {
        $integration = Integration::factory()->create(array_merge([
            'name' => $name,
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $app->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ], $attributes));
        $integration->issueSigningSecret();

        return $integration->refresh();
    }
}
