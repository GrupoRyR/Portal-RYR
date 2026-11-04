<?php

namespace Tests\Feature\Layouts;

use App\Models\Announcement;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use App\View\Notifications\NotificationList;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Home\HomeQueryBudget;
use Tests\TestCase;

/**
 * The bell of the portal header (odd/tasks/liquid-glass.md G4): the
 * account's unread mandatory announcements, its pending approvals and
 * tasks and the events of the next week, rendered by the server on the
 * home screen and on the secondary pages. The badge counts what needs the
 * person: the announcements to confirm and the pending items.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '6f1c2a9e-0000-4000-8000-000000000001';

    private Company $company;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'America/Bogota'));
        $this->company = Company::factory()->forTenant(self::TENANT)->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->user = User::factory()->microsoft($this->company)->create(['name' => 'Jhon Castro']);

        $app = App::factory()->forCompanies($this->company)->create();
        AppAccessRule::forCompany($app, $this->company);

        $tareas = App::factory()->create(['name' => 'Tareas', 'url' => 'https://tareas.segurex.com.co']);
        $this->integration = Integration::factory()->create([
            'abilities' => [Integration::WORK_WRITE],
            'app_id' => $tareas->id,
            'app_base_url' => 'https://tareas.segurex.com.co',
        ]);
    }

    public function test_the_bell_lists_what_needs_the_person_and_counts_it(): void
    {
        [$older, $newer, $approval, $task, $event] = $this->everyKind();

        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $page = $this->page($path);
            $bell = $this->bell($page);

            $summary = $page->query('.//summary', $bell)->item(0);
            $this->assertStringContainsString('Avisos, 4 sin leer', $this->text($summary), $path);
            $badge = $page->query('.//*[@data-bell-badge]', $summary)->item(0);
            $this->assertSame('true', $badge->getAttribute('aria-hidden'), $path);
            $this->assertSame('4', trim($badge->textContent), $path);

            // Announcements newest first, then approvals, tasks and events.
            $this->assertSame(
                ['announcement', 'announcement', 'approval', 'task', 'event'],
                array_map(fn ($item) => $item->getAttribute('data-bell-item'), iterator_to_array($page->query('.//*[@data-bell-item]', $bell))),
                $path,
            );
            $this->assertSame(
                [$newer->title, $older->title, $approval->title, $task->title, $event->title],
                array_map(fn ($title) => $this->text($title), iterator_to_array($page->query('.//*[@data-bell-item]//*[@data-bell-title]', $bell))),
                $path,
            );

            $this->assertSame(1, $page->query('.//a[@data-bell-all][@href="'.route('home').'#mi-dia"]', $bell)->length, $path);
            $this->assertSame(0, $page->query('.//*[@data-bell-empty]', $bell)->length, $path);
        }
    }

    public function test_each_notice_links_to_its_place(): void
    {
        [$older, , $approval, , $event] = $this->everyKind();
        $bell = $this->bell($page = $this->page(route('events.index', absolute: false)));

        $this->assertSame(1, $page->query('.//a[@href="'.route('announcements.show', $older->id).'"]', $bell)->length);
        // A pending item opens its app, in a new tab.
        $link = $page->query('.//a[@href="'.$approval->url.'"]', $bell);
        $this->assertSame(1, $link->length);
        $this->assertSame('_blank', $link->item(0)->getAttribute('target'));
        $this->assertStringContainsString('noopener', $link->item(0)->getAttribute('rel'));
        $this->assertSame(1, $page->query('.//a[@href="'.route('events.index').'#e-'.$event->id.'"]', $bell)->length);
    }

    public function test_read_optional_and_far_off_notices_stay_out(): void
    {
        $read = Announcement::factory()->mandatory()->create(['title' => 'Ya leído']);
        $read->markReadBy($this->user);
        Announcement::factory()->create(['title' => 'Solo informativo']);
        Event::factory()->create(['title' => 'Dentro de un mes', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHour()]);
        $this->item(['title' => 'Terminada', 'status' => WorkItem::STATUS_DONE]);

        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $bell = $this->bell($page = $this->page($path));

            $this->assertSame(0, $page->query('.//*[@data-bell-item]', $bell)->length, $path);
            $this->assertSame(0, $page->query('.//*[@data-bell-badge]', $bell)->length, $path);
        }
    }

    public function test_an_empty_bell_says_so(): void
    {
        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $bell = $this->bell($page = $this->page($path));

            $this->assertStringContainsString('Avisos, ninguno sin leer', $this->text($page->query('.//summary', $bell)->item(0)), $path);
            $this->assertSame(0, $page->query('.//*[@data-bell-badge]', $bell)->length, $path);
            $this->assertSame('No tienes avisos nuevos', $this->text($page->query('.//*[@data-bell-empty]', $bell)->item(0)), $path);
            $this->assertSame(0, $page->query('.//*[@data-bell-all]', $bell)->length, $path);
        }
    }

    public function test_an_event_alone_shows_without_a_badge(): void
    {
        Event::factory()->create(['title' => 'Pausa activa', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);

        $bell = $this->bell($page = $this->page('/'));

        $this->assertSame(1, $page->query('.//*[@data-bell-item="event"]', $bell)->length);
        $this->assertSame(0, $page->query('.//*[@data-bell-badge]', $bell)->length);
        $this->assertStringContainsString('Avisos, ninguno sin leer', $this->text($page->query('.//summary', $bell)->item(0)));
    }

    public function test_another_companys_notices_never_show(): void
    {
        $other = Company::factory()->forTenant('6f1c2a9e-0000-4000-8000-000000000002')->create();
        $colleague = User::factory()->microsoft($other)->create();
        Announcement::factory()->mandatory()->forCompanies($other)->create(['title' => 'Solo de la otra empresa']);
        Event::factory()->forCompanies($other)->create(['title' => 'Evento de la otra empresa', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        // Pending for an account of the other company, and for this person in another tenant.
        $this->item(['title' => 'Tarea de otra persona', 'assignee_tenant_id' => strtolower((string) $other->tenant_id), 'assignee_oid' => strtolower((string) $colleague->azure_oid)]);
        $this->item(['title' => 'Tarea en otro tenant', 'assignee_tenant_id' => strtolower((string) $other->tenant_id)]);
        Announcement::factory()->mandatory()->forCompanies($this->company)->create(['title' => 'De Segurex']);

        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $bell = $this->bell($page = $this->page($path));

            $this->assertSame(['De Segurex'], array_map(fn ($title) => $this->text($title), iterator_to_array($page->query('.//*[@data-bell-title]', $bell))), $path);
            $this->assertSame('1', trim($page->query('.//*[@data-bell-badge]', $bell)->item(0)->textContent), $path);
        }
    }

    public function test_titles_are_escaped_and_unsafe_links_fall_back_to_the_home(): void
    {
        Announcement::factory()->mandatory()->create(['title' => '<img src=x onerror=alert(1)>Política']);
        $this->item(['title' => '<script>alert(2)</script>Firmar', 'url' => 'javascript:alert(3)']);

        $html = $this->actingAs($this->user->fresh())->get(route('announcements.index'))->assertOk()->getContent();
        $bell = $this->bell($page = $this->xpath($html));

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Política', $html);
        $this->assertSame(0, $page->query('.//a[starts-with(@href, "javascript:")]', $bell)->length);
        $this->assertSame(1, $page->query('.//*[@data-bell-item="task"]//a[@href="'.route('home').'#tareas"][not(@target)]', $bell)->length);
    }

    public function test_the_badge_stops_at_nine_and_the_list_at_its_limit(): void
    {
        Announcement::factory()->mandatory()->count(7)->create();
        foreach (range(1, 6) as $i) {
            $this->item(['kind' => $i % 2 === 0 ? WorkItem::KIND_APPROVAL : WorkItem::KIND_TASK]);
        }

        foreach (['/', route('announcements.index', absolute: false)] as $path) {
            $bell = $this->bell($page = $this->page($path));

            $this->assertSame('9+', trim($page->query('.//*[@data-bell-badge]', $bell)->item(0)->textContent), $path);
            $this->assertStringContainsString('Avisos, más de 9 sin leer', $this->text($page->query('.//summary', $bell)->item(0)), $path);
            $this->assertSame(NotificationList::LIMIT, $page->query('.//*[@data-bell-item]', $bell)->length, $path);
        }
    }

    public function test_an_emergency_account_gets_an_empty_bell_without_queries(): void
    {
        $emergency = User::factory()->emergency()->create();
        Announcement::factory()->mandatory()->create();

        $queries = $this->countQueries(fn () => NotificationList::for($emergency, CarbonImmutable::now()));

        $this->assertSame(0, $queries);
        $this->assertTrue(NotificationList::for($emergency, CarbonImmutable::now())->isEmpty());
    }

    public function test_a_secondary_page_reads_the_bell_in_three_queries_whatever_the_rows(): void
    {
        $this->everyKind();
        $user = $this->user->fresh();
        $this->assertLessThanOrEqual(NotificationList::QUERIES, $this->countQueries(fn () => NotificationList::for($user, CarbonImmutable::now())));
        $page = route('announcements.index', absolute: false);
        $this->countPage($page); // Warms the settings and permission caches.
        $few = $this->countPage($page);

        $this->everyKind();
        $this->everyKind();

        $this->assertSame($few, $this->countPage($page));
        $this->assertLessThanOrEqual(NotificationList::QUERIES, $this->countQueries(fn () => NotificationList::for($user, CarbonImmutable::now())));
    }

    public function test_the_home_adds_one_query_for_the_bell_whatever_the_rows(): void
    {
        $this->everyKind();
        $this->countPage('/'); // Warms the settings and permission caches.
        $few = $this->countPage('/');

        $this->everyKind();
        $this->everyKind();

        $this->assertSame($few, $this->countPage('/'));
        $this->assertLessThanOrEqual(HomeQueryBudget::WITH_EVERY_BLOCK, $few);

        // The bell reuses «Tareas pendientes» (two queries) and the events
        // (one): its only query of its own is the unread announcements.
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });
        $this->actingAs($this->user->fresh())->get('/')->assertOk();
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        $from = fn (string $table) => count(array_filter($sql, fn (string $query) => preg_match('/^select .*? from "'.$table.'"/', $query) === 1));
        $this->assertSame(2, $from('work_items'));
        $this->assertSame(1, $from('events'));
        $this->assertCount(1, array_filter($sql, fn (string $query) => str_contains($query, '"requires_read" = ?')));
    }

    /**
     * Two unread mandatory announcements (the newer one second), an
     * approval, a task and an event in two days.
     *
     * @return array{0: Announcement, 1: Announcement, 2: WorkItem, 3: WorkItem, 4: Event}
     */
    private function everyKind(): array
    {
        return [
            Announcement::factory()->mandatory()->create(['title' => 'Confirma la política de IA', 'starts_at' => now()->subDays(3)]),
            Announcement::factory()->mandatory()->forCompanies($this->company)->create(['title' => 'Nuevo reglamento interno', 'starts_at' => now()->subHour()]),
            $this->item(['title' => 'Aprobar la orden de compra 881', 'kind' => WorkItem::KIND_APPROVAL, 'due_at' => now()->addDay()]),
            $this->item(['title' => 'Enviar el acta de HSE']),
            Event::factory()->create(['title' => 'Brigada de salud', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): WorkItem
    {
        return WorkItem::factory()->create([
            'integration_id' => $this->integration->id,
            'assignee_tenant_id' => self::TENANT,
            'assignee_oid' => strtolower((string) $this->user->azure_oid),
            ...$attributes,
        ]);
    }

    private function countPage(string $path): int
    {
        $user = $this->user->fresh();

        return $this->countQueries(fn () => $this->actingAs($user)->get($path)->assertOk());
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $callback();

        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }

    private function page(string $path): DOMXPath
    {
        return $this->xpath($this->actingAs($this->user->fresh())->get($path)->assertOk()->getContent());
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function bell(DOMXPath $page): \DOMNode
    {
        $bells = $page->query('//header[@data-portal-header]//details[@data-bell][@data-menu]');
        $this->assertSame(1, $bells->length);

        return $bells->item(0);
    }

    private function text(?\DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $node?->textContent));
    }
}
