<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Initial portal data: companies, roles, categories, the app catalog and
     * its group rules. Safe for production (`php artisan db:seed --force`):
     * no factories (Faker is a dev package), no users (people come from
     * Microsoft), and every seeder can run again without duplicating.
     */
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            RoleSeeder::class,
            AppCategorySeeder::class,
            AppCatalogSeeder::class,
            AppAccessRuleSeeder::class,
        ]);
    }
}
