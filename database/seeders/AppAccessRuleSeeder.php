<?php

namespace Database\Seeders;

use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * The initial Microsoft group rules of brief §5.2 (`APP-{Nombre}`, one per
 * app and company). Needs CompanySeeder and AppCatalogSeeder first.
 * Idempotent: existing rules are left as they are.
 */
class AppAccessRuleSeeder extends Seeder
{
    /** company slug => [group display name => app slug] */
    public const GROUP_RULES = [
        'segurex' => [
            'APP-TomaPedidos' => 'toma-pedidos',
            'APP-CuentasPorPagar' => 'cuentas-por-pagar',
            'APP-Cartera' => 'cartera',
            'APP-Camaras' => 'camaras',
        ],
        'derivados' => [
            'APP-Portafolio' => 'portafolio',
        ],
    ];

    public function run(): void
    {
        foreach (self::GROUP_RULES as $companySlug => $rules) {
            $company = Company::where('slug', $companySlug)->firstOrFail();

            foreach ($rules as $group => $appSlug) {
                AppAccessRule::forGroup(App::where('slug', $appSlug)->firstOrFail(), $company, $group);
            }
        }
    }
}
