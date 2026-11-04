<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administration in Liquid Glass (resources/css/glass.css, section 6): the
 * company photo behind, a glass sidebar and phone top bar, and the page on
 * one nearly opaque panel. Only the style changed: the off-canvas menu
 * keeps its Alpine state, focus trap and accessible toggle.
 */
class AdminGlassShellTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $company = Company::factory()->create(['slug' => 'derivados']);
        $this->admin = User::factory()->microsoft($company)->create();
        $this->admin->assignRole('super_admin');
    }

    public function test_the_shell_paints_the_company_photo_behind_glass(): void
    {
        $html = $this->html();

        $this->assertMatchesRegularExpression('/<html lang="es" data-brand="derivados">/', $html);
        $this->assertMatchesRegularExpression('/<body class="[^"]*\bg-canvas\b[^"]*">/', $html);
        $this->assertMatchesRegularExpression('/<div x-data="\{ open: false \}"(?:(?!<div).)*?\sclass="g-admin group\/shell [^"]*"/s', $html);
        $this->assertMatchesRegularExpression('/<header class="g-topbar g-admin-chrome [^"]*lg:hidden">/', $html);
        $this->assertMatchesRegularExpression('/<nav id="admin-sidebar"[^>]*class="g-admin-side g-admin-chrome [^"]*"/s', $html);
        $this->assertMatchesRegularExpression('/<main id="contenido" tabindex="-1" class="g-admin-main [^"]*">/', $html);
        $this->assertStringNotContainsString('brand-band', $html);
    }

    public function test_the_sidebar_keeps_its_alpine_hooks_and_focus_trap(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-menu="closed"', $html);
        $this->assertStringContainsString('x-bind:data-menu="open ? \'open\' : \'closed\'"', $html);
        $this->assertStringContainsString('x-on:keydown.escape.window="if (open) { open = false; $nextTick(() => $refs.toggle.focus()) }"', $html);
        $this->assertStringContainsString('x-trap="open"', $html);
        $this->assertStringContainsString('x-ref="toggle"', $html);
        $this->assertStringContainsString('x-ref="close"', $html);
        $this->assertStringContainsString('x-bind:aria-expanded="open.toString()"', $html);
        $this->assertStringContainsString('x-on:click="open = true; $nextTick(() => $refs.close.focus())"', $html);
        // The backdrop and the off-canvas classes that open and close it.
        $this->assertStringContainsString('group-data-[menu=open]/shell:block', $html);
        $this->assertStringContainsString('group-data-[menu=open]/shell:max-lg:translate-x-0', $html);
        $this->assertStringContainsString('lg:sticky lg:top-0 lg:h-dvh', $html);
    }

    public function test_the_sidebar_keeps_every_item_and_marks_the_current_one(): void
    {
        $html = $this->html();

        foreach (['Aplicaciones', 'Categorías', 'Anuncios', 'Eventos', 'Empresas', 'Roles', 'Auditoría', 'Informe de uso', 'Volver al portal'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        $this->assertMatchesRegularExpression('~<a href="'.preg_quote(route('admin.categories.index'), '~').'" aria-current="page"\s+class="[^"]*bg-brand-tint font-bold text-brand-ink~', $html);
    }

    private function html(): string
    {
        return $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk()->getContent();
    }
}
