<?php

namespace Database\Seeders;

use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * The real catalog of brief §1.1: five apps with their URLs, companies and
 * categories. Needs CompanySeeder and AppCategorySeeder first. Idempotent:
 * apps are matched by slug and their company links are synced.
 */
class AppCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = AppCategory::pluck('id', 'slug');
        $companies = Company::pluck('id', 'slug');

        foreach ($this->apps() as $slug => $app) {
            $companySlugs = $app['companies'];
            $category = $app['category'];
            unset($app['companies'], $app['category']);

            $model = App::updateOrCreate(['slug' => $slug], $app + [
                'app_category_id' => $categories[$category],
                'icon_path' => "icons/apps/{$slug}.svg",
                'icon_dark_path' => "icons/apps/{$slug}-dark.svg",
                'status' => App::STATUS_ACTIVE,
            ]);

            $model->companies()->sync(
                collect($companySlugs)->map(fn (string $company) => $companies[$company])->all(),
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function apps(): array
    {
        return [
            'toma-pedidos' => [
                'name' => 'Toma Pedidos SAP',
                'description' => 'Pedidos de venta directo a SAP',
                'accent_color' => '#4338CA',
                'url' => 'https://pedidos.segurex.com.co',
                'type' => App::TYPE_WEB,
                'open_in' => App::OPEN_NEW,
                'hosting' => 'godaddy',
                'login_type' => 'microsoft',
                'category' => 'ventas',
                'sort_order' => 10,
                'companies' => ['segurex'],
            ],
            'portafolio' => [
                'name' => 'Portafolio de productos',
                'description' => 'Catálogo de productos',
                'accent_color' => '#6D28D9',
                'url' => 'https://portafolio.derivadoscol.com.co/login.php',
                'type' => App::TYPE_WEB,
                'open_in' => App::OPEN_NEW,
                'hosting' => 'godaddy',
                'login_type' => 'own',
                'category' => 'ventas',
                'sort_order' => 20,
                'companies' => ['derivados'],
            ],
            'cuentas-por-pagar' => [
                'name' => 'Cuentas por pagar',
                'description' => 'Saldo y antigüedad de proveedores',
                'accent_color' => '#047857',
                'url' => 'https://sgx-cuentas-por-pagar.vercel.app/login',
                'type' => App::TYPE_WEB,
                'open_in' => App::OPEN_NEW,
                'hosting' => 'vercel',
                'login_type' => 'own',
                'category' => 'tesoreria',
                'sort_order' => 10,
                'companies' => ['segurex'],
            ],
            'cartera' => [
                'name' => 'Visualizador de cartera',
                'description' => 'Cartera por cliente y asesor',
                'accent_color' => '#B45309',
                'url' => 'https://sgx-visualizador-cartera.vercel.app/login',
                'type' => App::TYPE_WEB,
                'open_in' => App::OPEN_NEW,
                'hosting' => 'vercel',
                'login_type' => 'own',
                'category' => 'contabilidad',
                'sort_order' => 10,
                'companies' => ['segurex'],
            ],
            'camaras' => [
                'name' => 'Cámaras',
                'description' => 'Monitoreo de cámaras de seguridad',
                'accent_color' => '#334155',
                'url' => null,
                'type' => App::TYPE_LOCAL,
                'open_in' => App::OPEN_SAME,
                'hosting' => 'local',
                'login_type' => 'local',
                'category' => 'ti-o-sistemas',
                'sort_order' => 10,
                // One step per line (PhoneLocal canvas). There is no
                // installer yet, so the last step points to TI only.
                'local_instructions' => "Abre el menú Inicio de tu computador.\nEscribe «Cámaras» y ábrela.\nSi no aparece, pide ayuda a TI.",
                'download_url' => null,
                'companies' => ['segurex'],
            ],
        ];
    }
}
