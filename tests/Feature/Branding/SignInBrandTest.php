<?php

namespace Tests\Feature\Branding;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sign-in page stays neutral (no company is known yet) but names the
 * companies of the group with the same mark as the headers, in sentence
 * case and compact.
 */
class SignInBrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_company_row_is_named_without_the_group(): void
    {
        Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);

        $html = $this->get(route('login'))->assertOk()->getContent();

        // Vitrina: no «grupo» wording (Jhon, 2026-11-04), no shouted label.
        $this->assertMatchesRegularExpression('/<ul role="list" aria-label="Empresas" class="([^"]*)">/', $html);
        preg_match('/<ul role="list" aria-label="Empresas" class="([^"]*)">/', $html, $row);
        $this->assertStringNotContainsString('uppercase', $row[1]);
        $this->assertStringNotContainsString('Empresas del grupo', $html);
        $this->assertStringNotContainsString('Grupo Empresarial RYR', $html);
    }

    public function test_a_company_without_logo_shows_its_icon(): void
    {
        Company::factory()->create(['name' => 'Andina Química', 'icon_path' => 'brand/andina/icon-abc.png', 'logo_light_path' => null, 'logo_dark_path' => null]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('src="'.Storage::disk('public')->url('brand/andina/icon-abc.png').'"', false);
    }

    public function test_without_a_logo_the_row_shows_the_short_name_compactly(): void
    {
        Company::factory()->create([
            'name' => 'Soluciones y Derivados de Colombia S.A.S.',
            'short_name' => 'Derivados',
            'logo_light_path' => null,
            'logo_dark_path' => null,
            'icon_path' => null,
        ]);

        $html = $this->get(route('login'))->assertOk()->getContent();
        preg_match('/<ul role="list".*?<\/ul>/s', $html, $row);

        $this->assertStringContainsString('Derivados', $row[0]);
        $this->assertStringNotContainsString('Soluciones y Derivados de Colombia', $row[0]);
        $this->assertStringNotContainsString('truncate', $row[0]);
        $this->assertStringNotContainsString('text-lg', $row[0]);
    }
}
