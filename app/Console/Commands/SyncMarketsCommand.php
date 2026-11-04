<?php

namespace App\Console\Commands;

use App\Markets\MarketSeries;
use App\Markets\MarketSync;
use App\Markets\MarketSyncResult;
use Illuminate\Console\Command;

/**
 * Copies the figures of the home band (App\Markets\MarketSync): the TRM and
 * the euro in pesos, the policy rate and COLCAP of the Banco de la
 * República, and Brent and the input prices of FRED. Runs at 07:10 and
 * 13:10 Bogotá time (routes/console.php); run it by hand once after the
 * first deploy. Fails when some source could not be read: that figure
 * keeps its last value and the reason (a short code) is in the log.
 * Without FRED_API_KEY the FRED series are skipped with a line, not a
 * failure.
 */
class SyncMarketsCommand extends Command
{
    protected $signature = 'portal:sync-markets';

    protected $description = 'Copy the market figures of the home band (TRM, euro, BanRep, FRED)';

    /** Shorter than the band's labels where the band says more. */
    private const LABELS = [MarketSeries::TRM => 'TRM'];

    public function handle(MarketSync $sync): int
    {
        return $this->report($sync->run());
    }

    private function report(MarketSyncResult $result): int
    {
        $fresh = [];

        foreach (MarketSeries::all() as $code => $series) {
            $label = self::LABELS[$code] ?? $series->label;

            if (isset($result->saved[$code])) {
                $count = $result->saved[$code];
                $this->line(sprintf('%s: %d %s.', $label, $count, $count === 1 ? 'valor guardado' : 'valores guardados'));
            }

            if (isset($result->failed[$code])) {
                $this->error("{$label}: no se pudo actualizar ({$result->failed[$code]}); conserva el último valor. El motivo está en el log.");
            }

            if (($result->skipped[$code] ?? null) === 'fresh') {
                $fresh[] = $label;
            }
        }

        if ($fresh !== []) {
            $this->line('Ya consultadas hoy: '.implode(', ', $fresh).'. Son mensuales; se vuelven a consultar mañana.');
        }

        if (in_array('no_key', $result->skipped, true)) {
            $this->warn('Sin FRED_API_KEY: se omiten Brent y los insumos.');
        }

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
