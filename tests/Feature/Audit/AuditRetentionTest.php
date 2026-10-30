<?php

namespace Tests\Feature\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Auditoría keeps `activitylog.delete_records_older_than_days` (365) days:
 * `activitylog:clean` runs every day, also in production (`--force`), and
 * without `--days`, so the config decides.
 */
class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function event(): ?Event
    {
        return collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'activitylog:clean'));
    }

    public function test_the_audit_log_is_cleaned_daily_in_bogota_time(): void
    {
        $event = $this->event();

        $this->assertNotNull($event, 'activitylog:clean is not scheduled.');
        $this->assertSame('America/Bogota', (string) $event->timezone);
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $event->expression);
        $this->assertStringContainsString('--force', (string) $event->command);
        $this->assertStringNotContainsString('--days', (string) $event->command);
    }

    public function test_the_command_keeps_the_configured_days(): void
    {
        $this->assertSame(365, config('activitylog.delete_records_older_than_days'));
        $now = CarbonImmutable::parse('2026-10-30 04:30', 'America/Bogota');

        $this->travelTo($now->subDays(366));
        activity('security')->event('mcp.tool_called')->log('old');
        $this->travelTo($now->subDays(364));
        activity('security')->event('mcp.tool_called')->log('recent');
        $this->travelTo($now);

        $this->artisan('activitylog:clean', ['--force' => true])->assertSuccessful();

        $this->assertSame(['recent'], Activity::query()->pluck('description')->all());
    }
}
