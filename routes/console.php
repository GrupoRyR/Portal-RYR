<?php

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
