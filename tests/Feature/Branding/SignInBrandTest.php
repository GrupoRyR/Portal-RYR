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

    public function test_the_group_label_is_in_sentence_case(): void
    {
        Company::factory()->create();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<p class="([^"]*)">Grupo Empresarial RYR<\/p>/', $html);
        preg_match('/<p class="([^"]*)">Grupo Empresarial RYR<\/p>/', $html, $label);
        $this->assertStringNotContainsString('uppercase', $label[1]);
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
