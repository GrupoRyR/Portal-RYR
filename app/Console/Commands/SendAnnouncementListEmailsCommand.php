<?php

namespace App\Console\Commands;

use App\Announcements\AnnouncementListEmails;
use App\Calendar\EventListEmails;
use Illuminate\Console\Command;

/**
 * Emails the published announcements and events that ask for it to each
 * company's all-staff list (App\Announcements\AnnouncementListEmails,
 * App\Calendar\EventListEmails): at most BATCH emails of each per run.
 * Scheduled every minute without overlapping (routes/console.php); with
 * nothing to send it does nothing. A failure is recorded for that list
 * only, so the run still succeeds.
 */
class SendAnnouncementListEmailsCommand extends Command
{
    protected $signature = 'portal:send-announcement-list-emails';

    protected $description = 'Email the published announcements and events that ask for it to each company\'s all-staff list';

    public function handle(AnnouncementListEmails $announcements, EventListEmails $events): int
    {
        $first = $announcements->send();
        $second = $events->send();
        $sent = $first['sent'] + $second['sent'];
        $failed = $first['failed'] + $second['failed'];

        if ($sent + $failed > 0) {
            $this->info("Listas de correo: {$sent} correos enviados, {$failed} con error.");
        }

        return self::SUCCESS;
    }
}
