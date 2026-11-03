<?php

namespace App\Console\Commands;

use App\Digest\WeeklyDigests;
use Illuminate\Console\Command;

/**
 * Emails the Monday digest to the all-staff list of each company that has
 * «Resumen de los lunes» on (App\Digest\WeeklyDigests): once per company
 * and week. Scheduled on Mondays every hour from 07:00 to 12:00 in Bogotá
 * without overlapping (routes/console.php); a later run only catches up
 * the companies the first one did not record, and outside that window it
 * sends nothing. A failure is recorded for that list only, so the run
 * still succeeds.
 */
class SendWeeklyDigestCommand extends Command
{
    protected $signature = 'portal:send-weekly-digest';

    protected $description = 'Email the Monday digest to each company\'s all-staff list';

    public function handle(WeeklyDigests $digests): int
    {
        $result = $digests->send();

        if (array_sum($result) > 0) {
            $this->info("Resumen de los lunes: {$result['sent']} enviados, {$result['empty']} sin contenido, {$result['failed']} con error.");
        }

        return self::SUCCESS;
    }
}
