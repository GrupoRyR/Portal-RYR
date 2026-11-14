<?php

namespace Tests\Feature\Agent;

use App\Auth\MicrosoftLogin;
use App\Models\Announcement;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Home\HomeQueryBudget;
use Tests\TestCase;

/**
 * What the agent's button adds to the home page in queries: nothing beyond
 * HomeQueryBudget::AGENT_PANEL (the token is asked for on click, so no
 * management check runs at render), and never one per row of the page. Hosts and keys are invented.
 */
class AgentPanelQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'cccccccc-0000-4000-8000-000000000001';

    private Company $company;

    private User $ordinary;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'America/Bogota'));

        $this->company = Company::factory()->withManagementGroup(self::GROUP)->create(['slug' => 'segurex', 'domains' => ['segurex.com']]);
        $this->ordinary = User::factory()->microsoft($this->company)->create(['email' => 'ana.mail@segurex.com', 'upn' => 'ana@segurex.com']);
        $this->manager = User::factory()->microsoft($this->company)->create(['email' => 'luis.mail@segurex.com', 'upn' => 'luis@segurex.com']);
        $this->manager->assignRole(MicrosoftLogin::MANAGEMENT);
        $this->manager->syncGroups([['id' => self::GROUP, 'display_name' => 'APP-PortalGerencia', 'security_group' => true]]);
    }

    private function configure(bool $on): void
    {
        config([
            'agent.origin' => $on ? 'https://agente.example.test' : null,
            'agent.panel_key' => $on ? base64_encode(str_repeat("\x05\x06\x07\x08", 8)) : null,
        ]);
    }

    private function rows(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $app = App::factory()->forCompanies($this->company)->create();
            AppAccessRule::forCompany($app, $this->company);
            Announcement::factory()->create(['title' => "Anuncio {$i}", 'starts_at' => now()->subDays($i)]);
        }
    }

    private function countQueries(User $user): int
    {
        $user = $user->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        app('events')->forget(QueryExecuted::class);

        return $count;
    }

    /** @return array{int, int} the home's queries without and with the agent's button */
    private function offAndOn(User $user): array
    {
        $this->configure(false);
        $off = $this->countQueries($user);
        $this->configure(true);

        return [$off, $this->countQueries($user)];
    }

    /** @return array<string, array{string, int}> */
    public static function accounts(): array
    {
        return [
            'ordinary account' => ['ordinary', HomeQueryBudget::MAX],
            'management account' => ['manager', HomeQueryBudget::WITH_EVERY_BLOCK],
        ];
    }

    #[DataProvider('accounts')]
    public function test_the_button_adds_a_small_fixed_number_of_queries_whatever_the_page_holds(string $who, int $budget): void
    {
        $user = $this->{$who};
        $this->countQueries($user); // Warms the settings and permission caches, as on any later request.

        $this->rows(1);
        [$offFew, $onFew] = $this->offAndOn($user);
        $this->rows(6);
        [$offMany, $onMany] = $this->offAndOn($user);

        $this->assertStringContainsString('data-agente-panel', $this->actingAs($user->fresh())->get('/')->getContent());
        // The extra cost is bounded and does not grow with the page.
        $this->assertLessThanOrEqual(HomeQueryBudget::AGENT_PANEL, $onFew - $offFew);
        $this->assertLessThanOrEqual(HomeQueryBudget::AGENT_PANEL, $onMany - $offMany);
        $this->assertLessThanOrEqual($budget + HomeQueryBudget::AGENT_PANEL, $onMany);
    }

    public function test_an_account_the_agent_does_not_accept_costs_nothing_extra(): void
    {
        $guest = User::factory()->microsoft($this->company)->create(['email' => 'g@example.test', 'is_guest' => true]);
        $this->countQueries($guest);

        [$off, $on] = $this->offAndOn($guest);

        $this->assertLessThanOrEqual(HomeQueryBudget::AGENT_PANEL, $on - $off);
        $this->assertStringNotContainsString('data-agente-panel', $this->actingAs($guest->fresh())->get('/')->getContent());
    }
}
