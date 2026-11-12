<?php

namespace Tests\Feature\Agent\Views;

use App\Agent\Views\AgentViews;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The views are created from a migration, so every column they read must
 * already exist when it runs. SQLite does not check a view's columns when
 * it is created (MariaDB does, and fails with «Unknown column»), so this
 * test builds the schema the way `migrate` does, in file order and stopping
 * at the views migration, and then reads every view by its explicit
 * columns: SQLite resolves them on that first read. It failed once, when the
 * views migration sorted before the one that adds `users.upn`.
 */
class MigrationOrderTest extends TestCase
{
    private const DIRECTORY = 'database/migrations';

    /** @return list<string> Absolute paths, in the order `migrate` runs them. */
    private function migrationFiles(): array
    {
        $files = glob(base_path(self::DIRECTORY.'/*.php')) ?: [];
        sort($files);

        return $files;
    }

    private function viewsMigration(): string
    {
        $found = array_values(array_filter(
            $this->migrationFiles(),
            fn (string $file) => str_contains((string) file_get_contents($file), 'AgentViews::install'),
        ));

        $this->assertCount(1, $found, 'exactly one migration creates the agent views');

        return $found[0];
    }

    public function test_every_view_can_be_read_right_after_the_views_migration(): void
    {
        $views = $this->viewsMigration();
        $upTo = array_slice($this->migrationFiles(), 0, array_search($views, $this->migrationFiles(), true) + 1);

        $migrator = $this->app->make('migrator');
        $migrator->getRepository()->createRepository();
        $migrator->run($upTo);

        foreach (AgentViews::names() as $view) {
            $columns = implode(', ', AgentViews::columns($view));

            // Throws «no such column» if the schema was not ready when the view was created.
            $this->assertSame([], DB::select("SELECT {$columns} FROM {$view} LIMIT 0"), $view);
        }
    }

    public function test_the_views_come_after_the_migration_that_adds_the_upn(): void
    {
        $files = array_map('basename', $this->migrationFiles());
        $upn = array_values(array_filter($files, fn (string $name) => str_contains($name, 'add_upn_to_users_table')));

        $this->assertCount(1, $upn);
        $this->assertGreaterThan(array_search($upn[0], $files, true), array_search(basename($this->viewsMigration()), $files, true));
    }
}
