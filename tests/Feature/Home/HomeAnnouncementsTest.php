<?php

namespace Tests\Feature\Home;

use App\Models\Announcement;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HomeAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam']);
        $this->ana = User::factory()->microsoft($this->segurex)->create();

        $app = App::factory()->forCompanies($this->segurex)->create();
        AppAccessRule::forCompany($app, $this->segurex);
    }

    public function test_without_announcements_nothing_is_rendered(): void
    {
        Announcement::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Para otra empresa']);

        $this->home()
            ->assertDontSee('data-announcements', false)
            ->assertDontSee('data-announcement-banner', false)
            ->assertDontSeeText('Para otra empresa');
    }

    public function test_the_desktop_aside_lists_up_to_three_announcements(): void
    {
        Announcement::factory()->pinned()->create([
            'title' => 'Ya puedes entrar con tu cuenta de Microsoft',
            'body' => 'Toma Pedidos SAP ya no pide otra contraseña.',
            'starts_at' => CarbonImmutable::parse('2026-10-05 08:00', 'America/Bogota'),
            'image_path' => 'announcements/1/abc.webp',
        ]);
        Announcement::factory()->create(['title' => 'Segundo', 'starts_at' => now()->subDays(2)]);
        Announcement::factory()->create(['title' => 'Tercero', 'starts_at' => now()->subDays(3)]);
        Announcement::factory()->create(['title' => 'Cuarto', 'starts_at' => now()->subDays(4)]);

        $aside = $this->section($this->home(), 'data-announcements');

        $this->assertStringContainsString('Anuncios', $aside);
        $this->assertStringContainsString('href="'.route('announcements.index').'"', $aside);
        $this->assertStringContainsString('Ver todos', $aside);
        $this->assertStringContainsString('Fijado', $aside);
        $this->assertStringContainsString('5 de octubre', $aside);
        $this->assertStringContainsString('Ya puedes entrar con tu cuenta de Microsoft', $aside);
        $this->assertStringContainsString('Toma Pedidos SAP ya no pide otra contraseña.', $aside);
        $this->assertStringContainsString('src="'.Storage::disk('public')->url('announcements/1/abc.webp').'"', $aside);
        $this->assertStringContainsString('loading="lazy"', $aside);
        $this->assertStringContainsString('width="1280" height="720"', $aside);
        $this->assertStringContainsString('Tercero', $aside);
        $this->assertStringNotContainsString('Cuarto', $aside);
        $this->assertSame(1, substr_count($aside, 'Fijado'));
    }

    public function test_the_phone_banner_shows_the_top_announcement_and_links_to_it(): void
    {
        Announcement::factory()->create(['title' => 'Más reciente', 'starts_at' => now()->subHour()]);
        $pinned = Announcement::factory()->pinned()->create(['title' => 'El fijado', 'starts_at' => now()->subDays(3)]);

        $banner = $this->section($this->home(), 'data-announcement-banner');

        $this->assertStringContainsString('href="'.route('announcements.index').'#a-'.$pinned->id.'"', $banner);
        $this->assertStringContainsString('El fijado', $banner);
        $this->assertStringContainsString('Anuncio fijado', $banner);
        $this->assertStringNotContainsString('Más reciente', $banner);
    }

    public function test_user_text_is_escaped_and_the_body_keeps_its_line_breaks_as_plain_text(): void
    {
        Announcement::factory()->create([
            'title' => '<script>alert("t")</script>',
            'body' => "Primera línea\n<b>negrita</b> **no markdown**",
        ]);

        $response = $this->home();

        $response->assertDontSee('<script>alert("t")</script>', false)
            ->assertSee('&lt;script&gt;alert(&quot;t&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<b>negrita</b>', false)
            ->assertSee('&lt;b&gt;negrita&lt;/b&gt; **no markdown**', false);

        $this->assertStringContainsString('whitespace-pre-line', $this->section($response, 'data-announcements'));
    }

    public function test_the_home_page_still_loads_no_livewire(): void
    {
        Announcement::factory()->create();

        $this->home()
            ->assertDontSee('livewire', false)
            ->assertDontSee('x-data', false);
    }

    public function test_the_query_count_does_not_grow_with_the_announcements(): void
    {
        Announcement::factory()->create();
        $few = $this->countQueries();

        Announcement::factory()->count(6)->create();
        Announcement::factory()->forCompanies($this->segurex)->count(3)->create();

        $this->assertSame($few, $this->countQueries());
    }

    public function test_the_announcements_page_lists_every_visible_announcement(): void
    {
        $first = Announcement::factory()->pinned()->create(['title' => 'Primero']);
        Announcement::factory()->create(['title' => 'Segundo', 'starts_at' => now()->subDays(2)]);
        Announcement::factory()->expired()->create(['title' => 'Vencido']);
        Announcement::factory()->forCompanies(Company::factory()->create())->create(['title' => 'Ajeno']);

        $this->actingAs($this->ana)->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('id="a-'.$first->id.'"', false)
            ->assertSeeTextInOrder(['Anuncios', 'Primero', 'Segundo'])
            ->assertDontSeeText('Vencido')
            ->assertDontSeeText('Ajeno')
            ->assertSee('href="'.route('home').'"', false)
            ->assertDontSee('livewire', false);
    }

    public function test_the_announcements_page_is_paginated(): void
    {
        Announcement::factory()->count(12)->sequence(fn ($sequence) => [
            'title' => 'Anuncio '.($sequence->index + 1),
            'starts_at' => now()->subHours($sequence->index + 1),
        ])->create();

        $this->actingAs($this->ana)->get(route('announcements.index'))
            ->assertSeeText('Anuncio 10')
            ->assertDontSeeText('Anuncio 11')
            ->assertSee('?page=2', false);

        $this->actingAs($this->ana)->get(route('announcements.index', ['page' => 2]))
            ->assertSeeText('Anuncio 12');
    }

    public function test_the_announcements_page_explains_when_there_is_nothing(): void
    {
        $this->actingAs($this->ana)->get(route('announcements.index'))
            ->assertOk()
            ->assertSeeText('No hay anuncios por ahora.');
    }

    public function test_guests_cannot_read_announcements(): void
    {
        $this->get(route('announcements.index'))->assertRedirect(route('login'));
    }

    private function home(): TestResponse
    {
        return $this->actingAs($this->ana->fresh())->get('/')->assertOk();
    }

    /**
     * The markup of the (single) element carrying the attribute.
     */
    private function section(TestResponse $response, string $attribute): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        $nodes = (new \DOMXPath($document))->query('//*[@'.$attribute.']');

        $this->assertSame(1, $nodes->length, "Expected one element with {$attribute}.");

        return html_entity_decode((string) $document->saveHTML($nodes->item(0)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function countQueries(): int
    {
        $user = $this->ana->fresh();
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($user)->get('/')->assertOk();

        $result = $count;
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $result;
    }
}
