<?php

namespace App\Console\Commands;

use App\Push\PushSubscriptions;
use Illuminate\Console\Command;

/**
 * Forgets the devices that can no longer get phone notices
 * (App\Push\PushSubscriptions::prune()): accounts that have not signed in
 * for push.max_inactive_days, of an inactive company, without a company
 * or the emergency one. Scheduled daily (routes/console.php).
 */
class PrunePushSubscriptionsCommand extends Command
{
    protected $signature = 'portal:prune-push-subscriptions';

    protected $description = 'Delete the phone notice subscriptions of inactive accounts and companies';

    public function handle(PushSubscriptions $subscriptions): int
    {
        $deleted = $subscriptions->prune();

        if ($deleted > 0) {
            $this->info("Avisos en el celular: {$deleted} dispositivos olvidados.");
        }

        return self::SUCCESS;
    }
}
