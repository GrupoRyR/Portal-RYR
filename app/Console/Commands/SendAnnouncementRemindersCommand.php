<?php

namespace App\Console\Commands;

use App\Announcements\AnnouncementReminders;
use Illuminate\Console\Command;

/**
 * Sends the reminders asked for with «Enviar recordatorio»
 * (App\Announcements\AnnouncementReminders): at most BATCH emails per run.
 * Scheduled every minute without overlapping (routes/console.php); without
 * a pending reminder it does nothing. A mail failure is logged and recorded
 * for that person only, so the run still succeeds.
 */
class SendAnnouncementRemindersCommand extends Command
{
    protected $signature = 'portal:send-announcement-reminders';

    protected $description = 'Send a batch of the reminders requested for mandatory announcements';

    public function handle(AnnouncementReminders $reminders): int
    {
        $result = $reminders->send();

        if ($result['sent'] + $result['failed'] > 0) {
            $this->info("Recordatorios: {$result['sent']} correos enviados, {$result['failed']} con error.");
        }

        return self::SUCCESS;
    }
}
