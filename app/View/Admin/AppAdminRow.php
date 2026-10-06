<?php

namespace App\View\Admin;

use App\Models\App;
use App\Models\AppAccessRule;

/**
 * One row of the apps list in Administración, already worded in Spanish.
 * Needs `category`, `companies` and `accessRules.company` loaded.
 */
final readonly class AppAdminRow
{
    public const HOSTING_LABELS = [
        'godaddy' => 'GoDaddy',
        'vercel' => 'Vercel',
        'local' => 'En el equipo',
        'other' => 'Otro',
    ];

    public const LOGIN_LABELS = [
        'microsoft' => 'Microsoft',
        'own' => 'Propio',
        'local' => 'No aplica',
    ];

    public function __construct(
        public App $app,
        public string $companies,
        public string $category,
        public string $type,
        public string $login,
        public AppAdminStatus $status,
        public ?string $statusDetail,
        public string $audience,
    ) {}

    public static function from(App $app): self
    {
        $status = AppAdminStatus::of($app);

        return new self(
            app: $app,
            companies: self::companiesLabel($app),
            category: (string) $app->category?->name,
            type: $app->isLocal()
                ? 'Instalada en el equipo'
                : 'Web · '.(self::HOSTING_LABELS[$app->hosting] ?? $app->hosting),
            login: self::LOGIN_LABELS[$app->login_type] ?? $app->login_type,
            status: $status,
            statusDetail: $status === AppAdminStatus::Failing && $app->external_status_at !== null
                ? 'reportado '.$app->external_status_at->diffForHumans()
                : null,
            audience: self::audience($app),
        );
    }

    private static function companiesLabel(App $app): string
    {
        if ($app->available_to_all_companies) {
            return 'Todas';
        }

        $names = $app->companies->map(fn ($company) => $company->short_name ?: $company->name)->sort()->values();

        return $names->isEmpty() ? 'Ninguna' : $names->implode(', ');
    }

    /**
     * Group names, «Toda la empresa» and the number of single users, in
     * that order. The company is named when the rules span several.
     */
    private static function audience(App $app): string
    {
        $rules = $app->accessRules;

        if ($rules->isEmpty()) {
            return 'Nadie todavía';
        }

        $severalCompanies = $rules->pluck('company_id')->unique()->count() > 1;
        $suffix = fn (AppAccessRule $rule) => $severalCompanies && $rule->company
            ? ' ('.($rule->company->short_name ?: $rule->company->name).')'
            : '';

        $parts = $rules->where('type', AppAccessRule::TYPE_GROUP)
            ->sortBy('value')
            ->map(fn (AppAccessRule $rule) => $rule->value.$suffix($rule))
            ->merge($rules->where('type', AppAccessRule::TYPE_COMPANY)
                ->map(fn (AppAccessRule $rule) => 'Toda la empresa'.$suffix($rule)));

        $users = $rules->where('type', AppAccessRule::TYPE_USER)->count();

        if ($users > 0) {
            $parts->push($users === 1 ? '1 usuario' : "{$users} usuarios");
        }

        return $parts->values()->implode(', ');
    }
}
