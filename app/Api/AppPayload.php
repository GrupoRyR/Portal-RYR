<?php

namespace App\Api;

use App\Models\App;
use App\Models\Company;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * One app as the API shows it (GET /api/v1/apps): what an external system
 * needs to know about it, never its users or access rules. Dates are ISO
 * 8601 with the Bogotá offset.
 */
final class AppPayload
{
    /**
     * @param  Collection<int, Company>  $activeCompanies  For apps available to every company.
     * @return array<string, mixed>
     */
    public static function from(App $app, Collection $activeCompanies): array
    {
        $companies = $app->available_to_all_companies ? $activeCompanies : $app->companies;

        return [
            'slug' => $app->slug,
            'name' => $app->name,
            'type' => $app->type,
            'url' => $app->url,
            'category' => $app->category === null ? null : ['slug' => $app->category->slug, 'name' => $app->category->name],
            'all_companies' => $app->available_to_all_companies,
            'companies' => $companies
                ->map(fn (Company $company) => ['slug' => $company->slug, 'name' => $company->short_name ?: $company->name])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'hosting' => $app->hosting,
            'login_type' => $app->login_type,
            'status' => self::status($app),
            'updated_at' => self::date($app->updated_at),
        ];
    }

    /**
     * `current` is what people see on the card: maintenance (set by TI)
     * wins over a failure reported by an external system.
     *
     * @return array<string, mixed>
     */
    public static function status(App $app): array
    {
        return [
            'current' => match (true) {
                $app->inMaintenance() => 'maintenance',
                $app->hasReportedFailure() => 'failing',
                default => 'active',
            },
            'maintenance' => $app->inMaintenance()
                ? ['message' => $app->maintenance_message, 'until' => self::date($app->maintenance_until)]
                : null,
            'reported' => $app->external_status === null
                ? null
                : [
                    'status' => $app->external_status,
                    'message' => $app->external_status_message,
                    'reported_at' => self::date($app->external_status_at),
                ],
        ];
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone(config('app.timezone')))->format(DATE_ATOM);
    }
}
