<?php

namespace Tests\Feature\Assistant;

use App\Assistant\AssistantLog;
use App\Assistant\AssistantSwitch;
use App\Assistant\Budget\AssistantBudget;
use App\Assistant\Budget\BudgetAlertNotice;
use App\Assistant\Budget\Pricing;
use App\Assistant\Model\AssistantModel;
use App\Assistant\Model\ModelTurn;
use App\Http\Controllers\Assistant\AssistantController;
use App\Livewire\Admin\AssistantToggle;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The monthly spend cap (ASSISTANT_MONTHLY_BUDGET_USD, the calendar month
 * in Bogotá): each question's cost from its usage and the price table, no
 * API call at or over the cap, one email per threshold and month to the
 * super admins, and the usage report.
 */
class AssistantBudgetTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantModel $model;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'America/Bogota'));
        config([
            'assistant.enabled' => true,
            'services.anthropic.key' => 'sk-ant-test',
            'assistant.monthly_budget_usd' => 1.0,
            'assistant.model' => 'claude-haiku-5-5',
            'portal.super_admin_emails' => [],
        ]);
        DB::table('portal_settings')->insert(['key' => AssistantSwitch::SETTING, 'value' => 'true']);

        $this->model = new FakeAssistantModel;
        $this->app->instance(AssistantModel::class, $this->model);

        $company = Company::factory()->create(['short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($company)->create();
        $this->admin = User::factory()->microsoft($company)->create(['email' => 'admin@segurex.com']);
        $this->admin->assignRole('super_admin');
    }

    /**
     * A question line of $cost dollars at $when (Bogotá).
     */
    private function spent(float $cost, string $when = '2026-11-10 10:00'): void
    {
        $this->travelTo(CarbonImmutable::parse($when, 'America/Bogota'));
        activity('security')->event(AssistantLog::ASKED)->causedBy($this->user)->withProperties(['cost_usd' => $cost, 'feature' => 'chat'])->log(AssistantLog::ASKED);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'America/Bogota'));
    }

    private function ask(string $question = 'Hola'): TestResponse
    {
        return $this->actingAs($this->user)->post(route('assistant.ask'), ['pregunta' => $question]);
    }

    public function test_the_cost_is_each_usage_field_times_its_price(): void
    {
        // 10K of each: 30K of prompt, under Haiku's long-context threshold.
        $turn = new ModelTurn('end_turn', '', [], 10_000, 10_000, 'x', [], 10_000, 10_000);

        $this->assertEqualsWithDelta((0.10 + 0.50 + 0.125 + 0.01) / 100, Pricing::cost($turn, 'claude-haiku-5-5'), 1e-9);
        $this->assertEqualsWithDelta((2.0 + 10.0 + 2.5 + 0.2) / 100, Pricing::cost($turn, 'claude-sonnet-5-5'), 1e-9);
        $this->assertEqualsWithDelta((4.0 + 20.0 + 5.0 + 0.2) / 100, Pricing::cost($turn, 'claude-opus-5-5'), 1e-9);
        // Not in the table: the most expensive tier, so the cap stays safe.
        $this->assertEqualsWithDelta((10.0 + 50.0 + 12.5 + 1.0) / 100, Pricing::cost($turn, 'some-new-model'), 1e-9);

        // Haiku 5.5 over 100K tokens of prompt: the long-context rates.
        $long = new ModelTurn('end_turn', '', [], 100_001, 0, 'x');
        $this->assertEqualsWithDelta(100_001 * 0.50 / 1_000_000, Pricing::cost($long, 'claude-haiku-5-5'), 1e-9);
    }

    public function test_under_the_budget_the_model_is_called_and_the_cost_recorded(): void
    {
        $this->spent(0.50);
        $this->model->withUsage(90_000, 0)->answers('Hola.');

        $this->ask()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->model->requests);
        $this->assertEqualsWithDelta(0.009, Activity::query()->where('event', AssistantLog::ASKED)->latest('id')->first()->properties['cost_usd'], 1e-9);
        $this->assertEqualsWithDelta(0.509, app(AssistantBudget::class)->spentThisMonth(), 1e-9);
    }

    public function test_at_the_budget_nothing_reaches_the_api_and_it_is_audited(): void
    {
        $this->spent(1.00);

        $this->ask()->assertSessionHasErrors(['pregunta' => AssistantController::BUDGET_REACHED]);

        $this->assertSame([], $this->model->requests);
        $line = Activity::query()->where('event', AssistantLog::BUDGET_BLOCKED)->sole();
        $this->assertTrue($this->user->is($line->causer));
        $this->assertEquals(['spent_usd' => 1.0, 'budget_usd' => 1.0], $line->properties->all());
        $this->actingAs($this->user)->get(route('assistant.show'))
            ->assertSeeText('El asistente alcanzó el límite de uso de este mes. Vuelve a estar disponible el 1.º del próximo mes.');
    }

    public function test_the_month_rolls_over_at_midnight_in_bogota(): void
    {
        $this->spent(1.00, '2026-11-30 23:30');

        // 2026-12-01 03:00 UTC is still 30 November in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 03:00', 'UTC'));
        $this->assertTrue(app(AssistantBudget::class)->exceeded());

        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:01', 'America/Bogota'));
        $this->assertFalse(app(AssistantBudget::class)->exceeded());
        $this->model->answers('Nuevo mes.');
        $this->ask()->assertSessionHasNoErrors();
        $this->assertCount(1, $this->model->requests);
    }

    public function test_the_80_percent_alert_goes_once_to_the_super_admins(): void
    {
        $this->spent(0.795);
        $this->model->withUsage(100_000, 0)->answers('Uno.')->answers('Dos.');

        $this->ask('Uno'); // 0.795 + 0.01 = 0.805
        $this->ask('Dos');

        Notification::assertSentTo($this->admin, BudgetAlertNotice::class, fn (BudgetAlertNotice $notice) => $notice->threshold === 80);
        Notification::assertSentTimes(BudgetAlertNotice::class, 1);
        Notification::assertNotSentTo($this->user, BudgetAlertNotice::class);
        $this->assertTrue(DB::table('portal_settings')->where('key', AssistantBudget::SETTING_PREFIX.'2026-11:80')->exists());
        $this->assertSame(1, Activity::query()->where('event', AssistantLog::BUDGET_ALERT)->count());
    }

    public function test_the_100_percent_alert_is_separate_and_each_month_starts_again(): void
    {
        $this->spent(0.85);
        $this->model->withUsage(90_000, 300_000)->answers('Uno.')->answers('Dos.');

        $this->ask(); // 0.85 + 0.159 = 1.009: 80 % was not sent yet, so both go.

        Notification::assertSentTimes(BudgetAlertNotice::class, 2);

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'America/Bogota'));
        $this->spent(0.70, '2026-12-02 08:00');
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00', 'America/Bogota'));
        $this->ask();

        Notification::assertSentTimes(BudgetAlertNotice::class, 3);
        $this->assertTrue(DB::table('portal_settings')->where('key', AssistantBudget::SETTING_PREFIX.'2026-12:80')->exists());
    }

    public function test_a_mail_failure_never_blocks_the_question(): void
    {
        Notification::swap(new class extends NotificationFake
        {
            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new \RuntimeException('SMTP down');
            }
        });
        $this->spent(0.79);
        $this->model->withUsage(100_000, 0)->answers('Uno.');

        $this->ask()->assertRedirect(route('assistant.show').'#respuesta');
    }

    public function test_the_admin_card_shows_the_spend_and_the_models_read_only(): void
    {
        $this->spent(0.25);
        config(['assistant.editor_model' => 'claude-sonnet-5-5', 'assistant.fallback_model' => 'claude-opus-5-5']);

        Livewire::actingAs($this->admin)->test(AssistantToggle::class)
            ->assertSeeText('US$ 0,25 de US$ 1,00 (25 %)')
            ->assertSeeText('claude-haiku-5-5')
            ->assertSeeText('claude-sonnet-5-5')
            ->assertSeeText('claude-opus-5-5')
            ->assertDontSee('wire:model');
    }

    public function test_the_usage_report_prints_tokens_cache_hits_and_cost_per_day_and_feature(): void
    {
        $line = fn (string $when, string $feature, array $tokens) => (function () use ($when, $feature, $tokens) {
            $this->travelTo(CarbonImmutable::parse($when, 'America/Bogota'));
            activity('security')->event(AssistantLog::ASKED)->causedBy($this->user)->withProperties([
                'feature' => $feature, 'model_calls' => 2, 'cost_usd' => 0.01, ...$tokens,
            ])->log(AssistantLog::ASKED);
        })();

        $line('2026-11-19 10:00', 'chat', ['input_tokens' => 100, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 900, 'output_tokens' => 50]);
        $line('2026-11-20 08:00', 'draft', ['input_tokens' => 500, 'cache_creation_input_tokens' => 500, 'cache_read_input_tokens' => 0, 'output_tokens' => 70]);
        $line('2026-10-01 08:00', 'chat', ['input_tokens' => 999]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', 'America/Bogota'));

        $this->artisan('portal:assistant-usage', ['--days' => 2])
            ->expectsTable(
                ['Día', 'Uso', 'Preguntas', 'Llamadas', 'Entrada', 'Caché escrita', 'Caché leída', 'Salida', 'Aciertos de caché', 'Costo (US$)'],
                [
                    ['2026-11-19', 'chat', '1', '2', '100', '0', '900', '50', '90,0 %', '0,0100'],
                    ['2026-11-20', 'borrador', '1', '2', '500', '500', '0', '70', '0,0 %', '0,0100'],
                    ['Total', '', '2', '4', '600', '500', '900', '120', '45,0 %', '0,0200'],
                ],
            )
            ->expectsOutputToContain('Mes en curso (2026-11): US$ 0,02 de US$ 1,00 (2,0 %).')
            ->assertSuccessful();

        $this->artisan('portal:assistant-usage', ['--days' => 'x'])->assertFailed();
    }
}
