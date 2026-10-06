<?php

use App\Backups\BackupNotifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| Daily backups (spatie/laravel-backup)
|--------------------------------------------------------------------------
|
| Every night the scheduler (routes/console.php) cleans old copies, backs up
| the database and the uploaded files of the public disk into one zip on
| the private `backups` disk (storage/app/private/backups, never reachable
| from the web) and later checks that a recent copy exists. Problems are
| mailed to the super admins (PORTAL_SUPER_ADMIN_EMAILS). The mysqldump
| folder is set per connection in config/database.php (DB_DUMP_BINARY_PATH).
| Restore steps: docs/despliegue.md.
|
*/

return [

    'backup' => [
        /*
         * Folder of the copies inside the backups disk; also the name the
         * monitor looks for. Fixed so that APP_NAME can change freely.
         */
        'name' => 'portal-ryr',

        'source' => [
            'files' => [
                /*
                 * Only the uploaded files (company logos and icons, app icons,
                 * announcement images). The code is in Git and `.env` holds
                 * secrets, so neither goes into the zip.
                 */
                'include' => [
                    storage_path('app/public'),
                ],

                'exclude' => [],

                'follow_links' => false,

                'ignore_unreadable_directories' => false,

                /*
                 * Paths inside the zip start at the project folder
                 * (storage/app/public/...), so a restore copies them back
                 * to the same place.
                 */
                'relative_path' => base_path(),
            ],

            /*
             * The application's own connection (MySQL in production).
             */
            'databases' => [
                env('DB_CONNECTION', 'sqlite'),
            ],
        ],

        /*
         * Plain .sql inside the zip, which is already compressed: easier to
         * restore from cPanel or the command line.
         */
        'database_dump_compressor' => null,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,

            'compression_level' => 9,

            'filename_prefix' => '',

            /*
             * Private local disk defined in config/filesystems.php.
             */
            'disks' => [
                'backups',
            ],

            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * The copies stay on the same server, outside the web root, so they
         * are not encrypted (a lost password would make them useless).
         */
        'password' => null,

        'encryption' => 'default',

        /*
         * Open the new zip and check it has files before calling it a success.
         */
        'verify_backup' => true,

        'tries' => 1,

        'retry_delay' => 0,
    ],

    /*
     * Only problems are mailed (a failed backup or cleanup, or no recent
     * healthy copy). Successes are visible in Administración → Configuración.
     * Slack, Discord and webhooks are not used.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * Sends to config('portal.super_admin_emails'), the same list that
         * grants the super_admin role.
         */
        'notifiable' => BackupNotifiable::class,

        'mail' => [
            // Unused: BackupNotifiable routes the mail.
            'to' => [],

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',

            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',

            'username' => '',

            'avatar_url' => '',
        ],

        'webhook' => [
            'url' => '',
        ],
    ],

    /*
     * Backup messages go to the default log channel (storage/logs).
     */
    'log_channel' => null,

    /*
     * `backup:monitor` mails the super admins when the newest copy is older
     * than a day or the copies use more space than allowed.
     */
    'monitor_backups' => [
        [
            'name' => 'portal-ryr',
            'disks' => ['backups'],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 2500,
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        /*
         * Every copy of the last 7 days, then one per week for 4 more weeks;
         * anything older is deleted. The newest copy is never deleted. On a
         * shared hosting plan the oldest copies also go when all of them
         * together pass 2000 MB.
         */
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,

            'keep_daily_backups_for_days' => 0,

            'keep_weekly_backups_for_weeks' => 4,

            'keep_monthly_backups_for_months' => 0,

            'keep_yearly_backups_for_years' => 0,

            'delete_oldest_backups_when_using_more_megabytes_than' => 2000,
        ],

        'tries' => 1,

        'retry_delay' => 0,
    ],

];
