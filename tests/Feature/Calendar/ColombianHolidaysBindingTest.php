<?php

namespace Tests\Feature\Calendar;

use App\Calendar\ColombianHolidays;
use Tests\TestCase;

class ColombianHolidaysBindingTest extends TestCase
{
    public function test_the_container_builds_the_calendar_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'America/Lima']);

        $holidays = $this->app->make(ColombianHolidays::class);

        $this->assertSame('America/Lima', $holidays->forYear(2026)[0]->date->getTimezone()->getName());
        $this->assertSame($holidays, $this->app->make(ColombianHolidays::class));
    }
}
