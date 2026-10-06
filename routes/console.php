<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat: proves the cPanel cron is running `php artisan schedule:run`.
// Check it with: php artisan tinker --execute="echo cache('scheduler:last_run');"
Schedule::call(function (): void {
    Cache::forever('scheduler:last_run', now()->toIso8601String());
})
    ->name('scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();
