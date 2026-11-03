<?php

namespace App\Management;

use App\Models\Company;
use App\Models\CompanyKpi;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Read model of the «Panel gerencial» block of the home page
 * (odd/tasks/panel-gerencial.md): one company's current set of figures,
 * published by the SAP robot (App\Management\KpiPublisher), in one query.
 *
 * Only a «Gerencia» account of that same company may see it: check
 * `Gate::allows(ManagementPanel::ABILITY, $company)`
 * (App\Policies\ManagementPanelPolicy), or call forViewer(), which checks
 * it for the account's own company.
 */
final class ManagementPanel
{
    public const ABILITY = 'viewManagementPanel';

    /**
     * The company's current set, or null when the robot has not published
     * one. Never checks who is asking: see forViewer().
     */
    public function forCompany(Company|int $company): ?ManagementSnapshot
    {
        $rows = CompanyKpi::query()
            ->where('company_id', $company instanceof Company ? $company->getKey() : $company)
            ->orderBy('position')
            ->get(['key', 'label', 'value', 'unit', 'goal', 'note', 'period_label', 'as_of', 'source_label']);

        $first = $rows->first();

        if ($first === null) {
            return null;
        }

        return new ManagementSnapshot(
            $first->period_label,
            $first->as_of,
            $first->source_label,
            $rows->map(fn (CompanyKpi $row) => new ManagementKpi(
                $row->key,
                $row->label,
                $row->value,
                $row->unit,
                $row->goal,
                $row->note,
            ))->values()->all(),
        );
    }

    /**
     * The set of the account's own company when the account may see it;
     * null otherwise (no ability, or nothing published).
     */
    public function forViewer(User $user): ?ManagementSnapshot
    {
        $company = $user->company;

        if ($company === null || ! Gate::forUser($user)->allows(self::ABILITY, $company)) {
            return null;
        }

        return $this->forCompany($company);
    }
}
