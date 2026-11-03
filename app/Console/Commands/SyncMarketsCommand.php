<?php

namespace App\Console\Commands;

use App\Markets\MarketSync;
use App\Markets\MarketSyncResult;
use App\Models\MarketRate;
use Illuminate\Console\Command;

/**
 * Copies the TRM and the euro (in pesos) for the home band
 * (App\Markets\MarketSync). Runs at 07:10 and 13:10 Bogotá time
 * (routes/console.php); run it by hand once after the first deploy. Fails
 * when some source could not be read: that figure keeps its last value and
 * the reason (a short code) is in the log.
 */
class SyncMarketsCommand extends Command
{
    protected $signature = 'portal:sync-markets';

    protected $description = 'Copy the TRM and the euro in pesos from datos.gov.co and the ECB';

    private const LABELS = [MarketRate::TRM => 'TRM', MarketRate::EUR => 'Euro'];

    public function handle(MarketSync $sync): int
    {
        return $this->report($sync->run());
    }

    private function report(MarketSyncResult $result): int
    {
        foreach (self::LABELS as $code => $label) {
            if (isset($result->saved[$code])) {
                $count = $result->saved[$code];
                $this->line(sprintf('%s: %d %s.', $label, $count, $count === 1 ? 'valor guardado' : 'valores guardados'));
            }

            if (isset($result->failed[$code])) {
                $this->error("{$label}: no se pudo actualizar ({$result->failed[$code]}); conserva el último valor. El motivo está en el log.");
            }
        }

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
