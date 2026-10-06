<?php

namespace Tests\Feature\Layouts;

use App\Branding\BrandPalette;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_a_spanish_document_with_assets(): void
    {
        $this->blade('<x-layouts.app title="Prueba">Contenido</x-layouts.app>')
            ->assertSee('<html lang="es">', false)
            ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
            ->assertSee('<meta name="theme-color"', false)
            ->assertSee('<title>Prueba · '.config('app.name').'</title>', false)
            ->assertSee('/build/assets/', false)
            ->assertSee('Contenido');
    }

    public function test_it_ships_no_livewire_by_default(): void
    {
        $this->blade('<x-layouts.app>Contenido</x-layouts.app>')
            ->assertDontSee('livewireScriptConfig', false)
            ->assertDontSee('resources/js/livewire.js', false)
            ->assertDontSee('/assets/livewire-', false);
    }

    public function test_it_loads_livewire_when_asked(): void
    {
        $this->blade('<x-layouts.app livewire>Contenido</x-layouts.app>')
            ->assertSee('window.livewireScriptConfig', false)
            ->assertSee('/build/assets/livewire-', false);
    }

    public function test_it_never_requests_third_party_fonts(): void
    {
        $this->blade('<x-layouts.app>Contenido</x-layouts.app>')
            ->assertDontSee('fonts.googleapis.com', false)
            ->assertDontSee('fonts.bunny.net', false);
    }

    public function test_signed_out_pages_get_the_neutral_brand(): void
    {
        $this->blade('<x-layouts.app>Contenido</x-layouts.app>')
            ->assertSee('<style>'.BrandPalette::neutral()->toCss().'</style>', false);
    }

    public function test_signed_in_users_get_their_company_brand(): void
    {
        $company = Company::factory()->create([
            'color_primary' => '#183F94',
            'color_accent' => '#3E60A8',
            'color_dark' => '#03256C',
        ]);
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->blade('<x-layouts.app>Contenido</x-layouts.app>')
            ->assertSee('<style>'.BrandPalette::forCompany($company)->toCss().'</style>', false)
            ->assertSee('--brand-button:#183F94;', false);
    }

    public function test_users_without_a_company_get_the_neutral_brand(): void
    {
        $this->actingAs(User::factory()->create());

        $this->blade('<x-layouts.app>Contenido</x-layouts.app>')
            ->assertSee('<style>'.BrandPalette::neutral()->toCss().'</style>', false);
    }

    public function test_a_tampered_company_color_cannot_break_out_of_the_style_tag(): void
    {
        $company = Company::factory()->create(['color_primary' => '#FF671F', 'color_accent' => '</style><script>alert(1)</script>']);
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));

        $this->blade('<x-layouts.app>Contenido</x-layouts.app>')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('--brand-accent:#FF671F;', false);
    }
}
