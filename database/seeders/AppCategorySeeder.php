<?php

namespace Database\Seeders;

use App\Models\AppCategory;
use Illuminate\Database\Seeder;

/**
 * The initial app categories (brief §5.2), in home-screen order.
 * Idempotent: rows are matched by slug.
 */
class AppCategorySeeder extends Seeder
{
    public const CATEGORIES = [
        'ventas' => 'Ventas',
        'contabilidad' => 'Contabilidad',
        'tesoreria' => 'Tesorería',
        'ti-o-sistemas' => 'TI o Sistemas',
        'gerencia' => 'Gerencia',
        'produccion' => 'Producción',
        'diseno' => 'Diseño',
        'compras' => 'Compras',
        'otros' => 'Otros',
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::CATEGORIES as $slug => $name) {
            $order += 10;

            AppCategory::updateOrCreate(['slug' => $slug], ['name' => $name, 'sort_order' => $order]);
        }
    }
}
