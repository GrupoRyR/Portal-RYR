<?php

use App\Mcp\StaleClients;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: proves the cPanel cron is running `php artisan schedule:run`.
// Check it with: php artisan tinker --execute="echo cache('scheduler:last_run');"
Schedule::call(function (): void {
    Cache::forever('scheduler:last_run', now()->toIso8601String());
})
    ->name('scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

// Daily backups (config/backup.php), in Bogotá time: remove old copies,
// back up the database and the uploaded files, and in the morning check
// that a recent copy exists (problems are mailed to the super admins).
// `--isolated` keeps two backups from ever running at the same time.
Schedule::command('backup:clean')
    ->dailyAt('02:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Schedule::command('backup:run --isolated')
    ->dailyAt('02:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

Schedule::command('backup:monitor')
    ->dailyAt('08:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// The people of each company from Microsoft (App\Directory\DirectorySync),
// after the backups. A tenant that fails keeps its previous people.
Schedule::command('portal:sync-directory')
    ->dailyAt('03:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// «Respaldar ahora» (Configuración) only leaves a request; this runs it.
Schedule::command('portal:run-requested-backup')
    ->everyMinute()
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// Claude connector (Passport): delete revoked tokens and codes, and the
// ones that expired more than 7 days ago (`passport:purge`, --hours=168).
Schedule::command('passport:purge')
    ->dailyAt('04:00')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// passport:purge never deletes clients, and registering one is public:
// connector clients older than a day that never became a connection go,
// with their codes and tokens (App\Mcp\StaleClients).
Artisan::command('portal:purge-mcp-clients', function (StaleClients $clients) {
    $this->info('Clientes eliminados: '.$clients->purge());
})->purpose('Delete Claude connector OAuth clients that never became a connection');

Schedule::command('portal:purge-mcp-clients')
    ->dailyAt('04:15')
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// Auditoría keeps a year (config/activitylog.php, delete_records_older_than_days):
// older activity log rows go every day. `--force` because the command asks
// for confirmation in production; no `--days`, so the config decides. The
// Claude connector writes one row per tool call (up to 120 a minute per
// connection), so without this the table only grows.
Schedule::command('activitylog:clean --force')
    ->dailyAt('04:30')
    ->timezone('America/Bogota')
    ->withoutOverlapping();
