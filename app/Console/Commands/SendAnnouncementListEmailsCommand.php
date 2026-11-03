<?php

namespace App\Console\Commands;

use App\Announcements\AnnouncementListEmails;
use Illuminate\Console\Command;

/**
 * Emails the published announcements that ask for it to each company's
 * all-staff list (App\Announcements\AnnouncementListEmails): at most BATCH
 * emails per run. Scheduled every minute without overlapping
 * (routes/console.php); with nothing to send it does nothing. A failure is
 * recorded for that list only, so the run still succeeds.
 */
class SendAnnouncementListEmailsCommand extends Command
{
    protected $signature = 'portal:send-announcement-list-emails';

    protected $description = 'Email the published announcements that ask for it to each company\'s all-staff list';

    public function handle(AnnouncementListEmails $emails): int
    {
        $result = $emails->send();

        if ($result['sent'] + $result['failed'] > 0) {
            $this->info("Listas de correo: {$result['sent']} correos enviados, {$result['failed']} con error.");
        }

        return self::SUCCESS;
    }
}
