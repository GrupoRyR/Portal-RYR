<?php

namespace App\Management;

use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the whole set of management figures of one company with the one
 * a `kpis:write` integration sent (`PUT /api/v1/kpis`). The company is the
 * integration's own; the caller never chooses it.
 *
 * The replacement is atomic: inside one transaction the company row is
 * locked first (two robots, or two runs of one, never interleave), the old
 * rows are deleted and the new ones inserted, so a reader sees the old set
 * or the new one, never a mix. Then one `kpis.updated` entry is written to
 * the API log with the number of figures only, never their values.
 */
final class KpiPublisher
{
    public const AUDIT_EVENT = 'kpis.updated';

    /**
     * @param  array{period_label: string, as_of: string, source_label: string, items: list<array{key: string, label: string, value: int|float, unit?: ?string, goal?: int|float|null, note?: ?string}>}  $set
     * @return int The number of figures stored.
     */
    public function replace(Integration $integration, Company $company, array $set): int
    {
        $asOf = self::instant($set['as_of']);
        $now = now();

        $rows = array_map(fn (array $item, int $index) => [
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'position' => $index + 1,
            'key' => $item['key'],
            'label' => trim($item['label']),
            'value' => $item['value'],
            'unit' => self::optional($item['unit'] ?? null),
            'goal' => $item['goal'] ?? null,
            'note' => self::optional($item['note'] ?? null),
            'period_label' => trim($set['period_label']),
            'as_of' => $asOf,
            'source_label' => trim($set['source_label']),
            'created_at' => $now,
            'updated_at' => $now,
        ], $set['items'], array_keys($set['items']));

        DB::transaction(function () use ($company, $rows) {
            Company::query()->whereKey($company->id)->lockForUpdate()->first(['id']);

            CompanyKpi::query()->where('company_id', $company->id)->delete();
            CompanyKpi::query()->insert($rows);
        });

        activity(Integration::AUDIT_LOG)
            ->causedBy($integration)
            ->performedOn($company)
            ->event(self::AUDIT_EVENT)
            ->withProperties(['items' => count($rows)])
            ->log(self::AUDIT_EVENT);

        return count($rows);
    }

    /**
     * The same instant in the app time zone: the database keeps the wall
     * clock time without its offset and Eloquent reads it back in
     * config('app.timezone').
     */
    public static function instant(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
    }

    private static function optional(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
