<?php

namespace App\Console\Commands;

use App\Push\AnnouncementPushes;
use Illuminate\Console\Command;

/**
 * Sends the phone notices of the announcements published with «Enviar al
 * celular» (App\Push\AnnouncementPushes): at most config push.batch per
 * run. Scheduled every minute without overlapping (routes/console.php);
 * without VAPID settings or anything to send it does nothing. A failure is
 * recorded for that device only, so the run still succeeds.
 */
class SendAnnouncementPushesCommand extends Command
{
    protected $signature = 'portal:send-announcement-pushes';

    protected $description = 'Send a batch of the phone notices of announcements published with «Enviar al celular»';

    public function handle(AnnouncementPushes $pushes): int
    {
        $result = $pushes->send();

        if ($result['sent'] + $result['failed'] + $result['gone'] > 0) {
            $this->info("Avisos en el celular: {$result['sent']} enviados, {$result['failed']} con error, {$result['gone']} dispositivos ya no existen.");
        }

        return self::SUCCESS;
    }
}
