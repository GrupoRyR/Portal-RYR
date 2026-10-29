<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `external_id` is case-sensitive (docs/contrato-tareas-aprobaciones.md
 * §3.1): `TASK-1` and `task-1` are two items. SQLite compares text in binary
 * already; MySQL's default collation does not, so the column is binary there.
 * The migration is only rendered (pretend), never run against MySQL.
 */
class WorkItemsSchemaTest extends TestCase
{
    public function test_external_id_is_binary_collated_on_mysql(): void
    {
        $migration = require database_path('migrations/2026_10_30_000002_create_work_items_table.php');
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql');

        try {
            $queries = DB::connection('mysql')->pretend(fn () => $migration->up());
        } finally {
            DB::setDefaultConnection($previous);
        }

        $create = collect($queries)->pluck('query')->first(fn (string $sql) => str_starts_with($sql, 'create table `work_items`'));

        $this->assertNotNull($create);
        $this->assertStringContainsString('`external_id` varchar(190) collate \'utf8mb4_bin\' not null', $create);
    }
}
