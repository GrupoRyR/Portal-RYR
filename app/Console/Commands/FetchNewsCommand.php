<?php

namespace App\Console\Commands;

use App\News\NewsUpdater;
use Illuminate\Console\Command;

/**
 * Reads the active news sources and keeps their newest headlines
 * (App\News\NewsUpdater). Scheduled every hour in Bogotá without
 * overlapping (routes/console.php). A source that fails stores its short
 * code and the run still succeeds.
 */
class FetchNewsCommand extends Command
{
    protected $signature = 'portal:fetch-news';

    protected $description = 'Fetch the headlines of the active news sources';

    public function handle(NewsUpdater $updater): int
    {
        $result = $updater->run();

        $this->info("Noticias: {$result['fetched']} fuentes leídas, {$result['failed']} con error.");

        return self::SUCCESS;
    }
}
