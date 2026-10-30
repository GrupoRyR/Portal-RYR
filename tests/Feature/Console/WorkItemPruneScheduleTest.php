<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Deletion traces, done and cancelled work items older than 30 days are
 * removed every night (docs/contrato-tareas-aprobaciones.md §3.6).
 */
class WorkItemPruneScheduleTest extends TestCase
{
    public function test_work_items_are_pruned_every_night_in_bogota_time(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'model:prune') && str_contains((string) $event->command, 'WorkItem'));

        $this->assertNotNull($event, 'model:prune of WorkItem is not scheduled.');
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('America/Bogota', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
