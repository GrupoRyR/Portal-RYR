<?php

namespace App\Console\Commands;

use App\Directory\DirectorySync;
use Illuminate\Console\Command;

/**
 * Copies the people of every active company from Microsoft
 * (App\Directory\DirectorySync). Runs every night at 03:30 Bogotá time
 * (routes/console.php); run it by hand after giving a tenant's admin
 * consent. Fails when some tenant could not be copied: that tenant keeps
 * its previous people and the reason is in the log.
 */
class SyncDirectoryCommand extends Command
{
    protected $signature = 'portal:sync-directory';

    protected $description = 'Copy the people of each active company from Microsoft Graph';

    public function handle(DirectorySync $sync): int
    {
        $result = $sync->run();

        if ($result->companies === [] && ! $result->failed()) {
            $this->info('No hay empresas activas para copiar.');
        }

        foreach ($result->companies as $company) {
            $this->line(sprintf(
                '%s: %d %s, %d %s.',
                $company['name'],
                $company['saved'],
                $company['saved'] === 1 ? 'persona' : 'personas',
                $company['removed'],
                $company['removed'] === 1 ? 'quitada' : 'quitadas',
            ));
        }

        foreach ($result->failedTenants as $tenantId) {
            $this->error("No se pudo copiar el inquilino {$tenantId}; conserva sus datos anteriores. El motivo está en el log.");
        }

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
