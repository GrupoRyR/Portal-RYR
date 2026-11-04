<?php

namespace App\Console\Commands;

use App\Announcements\AnnouncementListEmails;
use App\Calendar\EventListEmails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails the published announcements and events that ask for it to each
 * company's all-staff list (App\Announcements\AnnouncementListEmails,
 * App\Calendar\EventListEmails): at most BATCH emails of each per run.
 * Scheduled every minute without overlapping (routes/console.php); with
 * nothing to send it does nothing. A failure of one email is recorded for
 * that list only. The two batches run independently: when one of them
 * fails as a whole (the database, say), the other still runs, the failure
 * is logged and printed, and the run ends as failed.
 */
class SendAnnouncementListEmailsCommand extends Command
{
    protected $signature = 'portal:send-announcement-list-emails';

    protected $description = 'Email the published announcements and events that ask for it to each company\'s all-staff list';

    public function handle(AnnouncementListEmails $announcements, EventListEmails $events): int
    {
        $sent = 0;
        $failed = 0;
        $broken = false;

        foreach (['Anuncios' => fn () => $announcements->send(), 'Eventos' => fn () => $events->send()] as $label => $batch) {
            try {
                $result = $batch();
                $sent += $result['sent'];
                $failed += $result['failed'];
            } catch (Throwable $exception) {
                $broken = true;
                // Only the class: a message could carry an address or a token.
                Log::error('A list email batch could not run.', ['batch' => $label, 'exception' => $exception::class]);
                $this->error($label.': no se pudo enviar el lote ('.$exception::class.').');
            }
        }

        if ($sent + $failed > 0) {
            $this->info("Listas de correo: {$sent} correos enviados, {$failed} con error.");
        }

        return $broken ? self::FAILURE : self::SUCCESS;
    }
}
