<?php

namespace Tests\Feature\Mcp;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class PurgeScheduleTest extends TestCase
{
    public function test_expired_and_revoked_oauth_rows_are_purged_daily(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'passport:purge'));

        $this->assertNotNull($event, 'passport:purge is not scheduled.');
        $this->assertSame('America/Bogota', (string) $event->timezone);
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $event->expression);
    }
}
