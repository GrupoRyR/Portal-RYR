<?php

namespace Tests\Feature\Agent\Views;

use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Agent\Views\Concerns\BuildsAgentWorld;
use Tests\TestCase;

/**
 * v_tareas_pendientes against WorkItem::scopeVisibleTo(), person by person.
 * The one per-person view: it must never show the items of somebody else.
 */
class TasksParityTest extends TestCase
{
    use BuildsAgentWorld;
    use RefreshDatabase;

    private User $anaSgx;

    private User $anaSdc;

    private User $beto;

    private User $carla;

    private User $guest;

    private User $emergency;

    private User $noCompany;

    private Integration $live;

    /** @var array<string, Integration> */
    private array $broken = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCompanies();

        // One person, two accounts (one per company) with the same email in mixed case.
        $this->anaSgx = $this->microsoftUser($this->sgx, 'Ana.Perez@Example.test');
        $this->anaSdc = $this->microsoftUser($this->sdc, 'ana.perez@example.test');
        // A tenant and object ID stored in upper case still match the lower-case ones of the item.
        $this->beto = $this->microsoftUser($this->sgx, 'beto@example.test');
        $this->beto->forceFill(['azure_oid' => strtoupper($this->beto->azure_oid), 'tenant_id' => strtoupper($this->beto->tenant_id)])->save();
        $this->carla = $this->microsoftUser($this->other, 'carla@example.test');
        $this->guest = $this->microsoftUser($this->sgx, 'invitado@example.test', ['is_guest' => true]);
        $this->emergency = $this->microsoftUser($this->sgx, 'emergencia@example.test', ['is_emergency' => true]);
        $this->noCompany = $this->microsoftUser(null, 'sin.empresa@example.test');

        $tasks = App::factory()->create(['slug' => 'tareas', 'name' => 'Tareas', 'url' => 'https://tareas.segurex.com.co']);
        $this->live = $this->workIntegration('Tareas', $tasks);
        $revoked = $this->workIntegration('Revocada', $tasks);
        $revoked->forceFill(['revoked_at' => '2026-11-01 00:00:00'])->save();
        $this->broken = [
            'revoked' => $revoked,
            'no-app' => $this->workIntegration('Sin app', $tasks, ['app_id' => null]),
            'no-ability' => $this->workIntegration('Sin habilidad', $tasks, ['abilities' => [Integration::CATALOG_READ]]),
        ];

        foreach ([$this->anaSgx, $this->anaSdc, $this->beto, $this->carla, $this->guest, $this->emergency, $this->noCompany] as $user) {
            $this->seedItemsOf($user);
        }

        $this->freeze('2026-11-12 10:00:00');
    }

    /** The prefix of the titles of an account's items: its id. */
    private function who(User $user): string
    {
        return 'u'.$user->id;
    }

    private function item(User $user, string $what, array $attributes = [], ?Integration $integration = null): WorkItem
    {
        return WorkItem::factory()->create(array_merge([
            'integration_id' => ($integration ?? $this->live)->id,
            'title' => $this->who($user).':'.$what,
            'assignee_tenant_id' => strtolower($user->tenant_id),
            'assignee_oid' => strtolower($user->azure_oid),
            'url' => 'https://tareas.segurex.com.co/t/'.Str::slug($what),
        ], $attributes));
    }

    private function seedItemsOf(User $user): void
    {
        $this->item($user, 'task-overdue', ['due_at' => '2026-11-11 09:00:00']);
        $this->item($user, 'approval-future', ['kind' => WorkItem::KIND_APPROVAL, 'due_at' => '2026-11-13 09:00:00', 'completable_from_portal' => false]);
        $this->item($user, 'task-no-due', ['due_at' => null, 'completable_from_portal' => false]);
        $this->item($user, 'due-now', ['due_at' => '2026-11-12 10:00:00']);
        $this->item($user, 'due-one-second-ago', ['due_at' => '2026-11-12 09:59:59']);
        $this->item($user, 'due-in-three-hours', ['due_at' => '2026-11-12 13:00:00']);
        $this->item($user, 'done', ['status' => WorkItem::STATUS_DONE]);
        $this->item($user, 'cancelled', ['status' => WorkItem::STATUS_CANCELLED]);
        $this->item($user, 'deleted')->delete();

        foreach ($this->broken as $name => $integration) {
            $this->item($user, "integration-{$name}", [], $integration);
        }
    }

    /** The expected rows of an account: what the portal shows it, by title. */
    private function expectedTitles(User $user): array
    {
        return WorkItem::query()->visibleTo($user)->pluck('title')->sort()->values()->all();
    }

    private function titlesOf(string $code, string $email): array
    {
        return DB::table('v_tareas_pendientes')->where('empresa', $code)->where('usuario_correo', $email)->pluck('titulo')->sort()->values()->all();
    }

    public function test_each_person_gets_the_items_the_portal_shows_the_account(): void
    {
        foreach ([[$this->anaSgx, 'SGX'], [$this->anaSdc, 'SDC'], [$this->beto, 'SGX']] as [$user, $code]) {
            $expected = $this->expectedTitles($user);

            $this->assertNotEmpty($expected);
            $this->assertSame($expected, $this->titlesOf($code, strtolower($user->email)), $user->email.' '.$code);
        }
    }

    public function test_the_same_email_in_two_companies_is_two_separate_sets(): void
    {
        $sgx = $this->titlesOf('SGX', 'ana.perez@example.test');
        $sdc = $this->titlesOf('SDC', 'ana.perez@example.test');

        $this->assertNotEmpty($sgx);
        $this->assertNotEmpty($sdc);
        $this->assertSame([], array_intersect($sgx, $sdc));
        $this->assertSame([], array_filter($sgx, fn (string $title) => ! str_starts_with($title, $this->who($this->anaSgx).':')));
        $this->assertSame([], array_filter($sdc, fn (string $title) => ! str_starts_with($title, $this->who($this->anaSdc).':')));
    }

    public function test_a_person_never_gets_the_items_of_another(): void
    {
        $owners = [];
        foreach ([[$this->anaSgx, 'SGX'], [$this->anaSdc, 'SDC'], [$this->beto, 'SGX']] as [$user, $code]) {
            $owners[$this->who($user)] = [$code, strtolower($user->email)];
        }

        $rows = DB::table('v_tareas_pendientes')->get();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame($owners[Str::before($row->titulo, ':')] ?? null, [$row->empresa, $row->usuario_correo], $row->titulo);
        }

        $this->assertSame([], array_filter($this->titlesOf('SGX', 'ana.perez@example.test'), fn (string $title) => str_starts_with($title, $this->who($this->beto).':')));
        $this->assertSame([], array_filter($this->titlesOf('SGX', 'beto@example.test'), fn (string $title) => str_starts_with($title, $this->who($this->anaSgx).':')));
    }

    public function test_the_email_comes_out_in_lower_case_and_nothing_else_about_the_person(): void
    {
        $this->assertSame('ana.perez@example.test', DB::table('v_tareas_pendientes')->where('titulo', 'like', $this->who($this->anaSgx).':%')->value('usuario_correo'));
        $this->assertSame(
            ['empresa', 'usuario_correo', 'tipo', 'titulo', 'vence', 'vencida', 'enlace', 'puede_completar'],
            array_keys((array) DB::table('v_tareas_pendientes')->first()),
        );
    }

    public function test_guests_emergency_accounts_and_people_without_a_mapped_company_get_nothing(): void
    {
        // The portal's own scope would show a guest its items; the panel does not use that account.
        $this->assertNotEmpty($this->expectedTitles($this->guest));

        foreach ([$this->guest, $this->emergency, $this->noCompany, $this->carla] as $user) {
            $this->assertSame(0, DB::table('v_tareas_pendientes')->where('usuario_correo', strtolower($user->email))->count(), $user->email);
        }

        $this->assertEqualsCanonicalizing(['SGX', 'SDC'], DB::table('v_tareas_pendientes')->distinct()->pluck('empresa')->all());
    }

    public function test_an_inactive_company_gets_no_rows(): void
    {
        $this->sdc->forceFill(['is_active' => false])->save();

        $this->assertSame([], $this->titlesOf('SDC', 'ana.perez@example.test'));
        $this->assertNotEmpty($this->titlesOf('SGX', 'ana.perez@example.test'));
    }

    public function test_pending_only_not_deleted_and_from_a_live_integration(): void
    {
        $titles = $this->titlesOf('SGX', 'beto@example.test');
        $who = $this->who($this->beto);

        $this->assertEqualsCanonicalizing(
            array_map(fn (string $what) => "{$who}:{$what}", ['task-overdue', 'approval-future', 'task-no-due', 'due-now', 'due-one-second-ago', 'due-in-three-hours']),
            $titles,
        );
    }

    public function test_the_fields_of_each_item(): void
    {
        $who = $this->who($this->beto);
        $rows = DB::table('v_tareas_pendientes')->where('empresa', 'SGX')->where('usuario_correo', 'beto@example.test')->get()->keyBy('titulo');

        $overdue = $rows["{$who}:task-overdue"];
        $this->assertSame('tarea', $overdue->tipo);
        $this->assertSame('2026-11-11 09:00:00', $overdue->vence);
        $this->assertSame(1, (int) $overdue->vencida);
        $this->assertSame('https://tareas.segurex.com.co/t/task-overdue', $overdue->enlace);

        $approval = $rows["{$who}:approval-future"];
        $this->assertSame('aprobacion', $approval->tipo);
        $this->assertSame(0, (int) $approval->vencida);
        $this->assertSame(0, (int) $approval->puede_completar);

        $this->assertNull($rows["{$who}:task-no-due"]->vence);
        $this->assertSame(0, (int) $rows["{$who}:task-no-due"]->vencida);
        // Due exactly now is not overdue yet; a second earlier is.
        $this->assertSame(0, (int) $rows["{$who}:due-now"]->vencida);
        $this->assertSame(1, (int) $rows["{$who}:due-one-second-ago"]->vencida);
        $this->assertSame(0, (int) $rows["{$who}:due-in-three-hours"]->vencida);
    }

    public function test_completable_is_never_lower_than_what_the_portal_allows(): void
    {
        foreach (WorkItem::query()->visibleTo($this->beto)->with('integration.app')->get() as $item) {
            $row = DB::table('v_tareas_pendientes')->where('titulo', $item->title)->first();

            if ($item->canBeCompletedFromPortal()) {
                $this->assertSame(1, (int) $row->puede_completar, $item->title);
            }
            if ($item->kind === WorkItem::KIND_APPROVAL) {
                $this->assertSame(0, (int) $row->puede_completar, $item->title);
            }
        }

        $this->assertSame(1, (int) DB::table('v_tareas_pendientes')->where('titulo', $this->who($this->beto).':task-overdue')->value('puede_completar'));
    }
}
