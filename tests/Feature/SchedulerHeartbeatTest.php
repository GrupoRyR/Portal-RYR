<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerHeartbeatTest extends TestCase
{
    private function heartbeatEvent(): ?Event
    {
        return collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => $event->description === 'scheduler-heartbeat');
    }

    public function test_heartbeat_is_scheduled_every_minute(): void
    {
        $event = $this->heartbeatEvent();

        $this->assertNotNull($event, 'The scheduler-heartbeat task is not registered.');
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_running_the_heartbeat_records_the_last_run(): void
    {
        Carbon::setTestNow('2026-10-05 08:30:00');

        $this->assertNull(Cache::get('scheduler:last_run'));

        $this->heartbeatEvent()->run($this->app);

        $this->assertSame(now()->toIso8601String(), Cache::get('scheduler:last_run'));

        Carbon::setTestNow();
    }
}
