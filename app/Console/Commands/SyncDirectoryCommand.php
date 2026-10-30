<?php

namespace App\Console\Commands;

use App\Directory\DirectorySync;
use App\Directory\DirectorySyncResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Copies the people of every active company from Microsoft
 * (App\Directory\DirectorySync). Runs every night at 03:30 Bogotá time
 * (routes/console.php); run it by hand after giving a tenant's admin
 * consent. Fails when some tenant could not be copied: that tenant keeps
 * its previous people and the reason is in the log. Only one copy runs at
 * a time: while one is running, another stops at once and fails.
 */
class SyncDirectoryCommand extends Command
{
    protected $signature = 'portal:sync-directory';

    protected $description = 'Copy the people of each active company from Microsoft Graph';

    /** Name of the lock that keeps two copies from running at once. */
    public const LOCK = 'portal:sync-directory';

    /** Longest a copy may hold the lock, if it dies without freeing it. */
    private const LOCK_SECONDS = 2 * 60 * 60;

    public function handle(DirectorySync $sync): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->error('Ya hay una copia del directorio en curso; esta no copió nada. Espere a que termine y vuelva a intentarlo.');

            return self::FAILURE;
        }

        try {
            return $this->report($sync->run());
        } finally {
            $lock->release();
        }
    }

    private function report(DirectorySyncResult $result): int
    {

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
