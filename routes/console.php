<?php

use App\Mcp\StaleClients;
use App\Models\WorkItem;
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

// The TRM and the euro in pesos for the home band (App\Markets\MarketSync),
// twice a day at :10 so the morning one lands after Banco de la República
// and the ECB have published. A source that fails keeps its last value.
Schedule::command('portal:sync-markets')
    ->twiceDailyAt(7, 13, 10)
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// «Respaldar ahora» (Configuración) only leaves a request; this runs it.
Schedule::command('portal:run-requested-backup')
    ->everyMinute()
    ->timezone('America/Bogota')
    ->withoutOverlapping();

// Tasks and approvals (docs/contrato-tareas-aprobaciones.md §3.6): remove
// deletion traces, done and cancelled items older than 30 days.
Schedule::command('model:prune', ['--model' => [WorkItem::class]])
    ->dailyAt('03:00')
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

// «Enviar recordatorio» (Admin, mandatory announcements) only records the
// request; this mails a batch of it every minute
// (App\Announcements\AnnouncementReminders). The overlap lock expires
// after 10 minutes, so a killed run does not block it for a day.
Schedule::command('portal:send-announcement-reminders')
    ->everyMinute()
    ->withoutOverlapping(10);

// Announcements that ask for it go to each company's all-staff list from
// the company's own mailbox (App\Announcements\AnnouncementListEmails),
// once each, when they are published. A run sends at most 10 emails of
// a few seconds each; the overlap lock expires after 10 minutes.
Schedule::command('portal:send-announcement-list-emails')
    ->everyMinute()
    ->withoutOverlapping(10);

// «Resumen de los lunes» (App\Digest\WeeklyDigests): on Mondays, every
// hour from 07:00 to 12:00 in Bogotá. The first run sends each company's
// digest once; the later ones only catch up the companies it did not
// record (a missed run, a switch turned on in the morning). After the noon
// run nothing goes out for that week. The overlap lock expires after 10
// minutes.
Schedule::command('portal:send-weekly-digest')
    ->cron('0 7-12 * * 1')
    ->timezone('America/Bogota')
    ->withoutOverlapping(10);
