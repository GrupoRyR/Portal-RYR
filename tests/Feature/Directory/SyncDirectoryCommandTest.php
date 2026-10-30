<?php

namespace Tests\Feature\Directory;

use App\Models\Company;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `php artisan portal:sync-directory`: the copy by hand, and every night.
 */
class SyncDirectoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD_TENANT = '11111111-1111-1111-1111-111111111111';

    private const BAD_TENANT = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => 'secret',
        ]);
        Http::fake([
            'login.microsoftonline.com/'.self::BAD_TENANT.'/*' => Http::response(['error' => 'unauthorized_client', 'error_codes' => [700016]], 400),
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => fn (Request $request) => Http::response(['value' => [
                ['id' => (string) Str::uuid(), 'displayName' => 'Ana Pérez', 'mail' => 'ana@segurex.com', 'accountEnabled' => true, 'userType' => 'Member'],
                ['id' => (string) Str::uuid(), 'displayName' => 'Luis Gómez', 'mail' => 'luis@segurex.com', 'accountEnabled' => true, 'userType' => 'Member'],
            ]]),
        ]);
    }

    public function test_it_prints_the_people_copied_per_company(): void
    {
        Company::factory()->forTenant(self::GOOD_TENANT)->create(['name' => 'Segurex Latam']);

        $this->artisan('portal:sync-directory')
            ->expectsOutputToContain('Segurex Latam: 2 personas, 0 quitadas.')
            ->assertSuccessful();
    }

    public function test_it_fails_when_a_tenant_could_not_be_copied(): void
    {
        Company::factory()->forTenant(self::GOOD_TENANT)->create(['name' => 'Segurex Latam']);
        Company::factory()->forTenant(self::BAD_TENANT)->create(['name' => 'Derivados']);

        $this->artisan('portal:sync-directory')
            ->expectsOutputToContain('Segurex Latam: 2 personas, 0 quitadas.')
            ->expectsOutputToContain(self::BAD_TENANT)
            ->assertFailed();
    }

    public function test_it_runs_every_night_in_bogota_time_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'portal:sync-directory'));

        $this->assertNotNull($event, 'portal:sync-directory is not scheduled.');
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertSame('America/Bogota', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
