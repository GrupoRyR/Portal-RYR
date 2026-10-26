<?php

namespace Tests\Feature\Branding;

use App\Branding\BrandPalette;
use App\Models\Company;
use Database\Seeders\CompanySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Official brand manual SGX/SDC (2026-10-23): the companies table holds the
 * manual's colors and the palette paints them as given.
 */
class BrandManualColorsTest extends TestCase
{
    use RefreshDatabase;

    private const MANUAL_COLORS = 'database/migrations/2026_10_26_113300_apply_brand_manual_colors.php';

    private const SEGUREX = [
        'color_primary' => '#F26522',
        'color_accent' => '#F26522',
        'color_dark' => '#58595B',
        'color_button' => '#C6531C',
        'color_ink' => '#943E15',
        'color_text' => '#58595B',
        'color_ink_dark_mode' => '#F26522',
        'color_text_dark_mode' => '#A8A9AA',
    ];

    private const DERIVADOS = [
        'color_primary' => '#0B5FA5',
        'color_accent' => '#0B5FA5',
        'color_dark' => '#073B66',
        'color_button' => '#0B5FA5',
        'color_ink' => '#073B66',
        'color_text' => '#073B66',
        'color_ink_dark_mode' => '#4D8ABD',
        'color_text_dark_mode' => '#4D8ABD',
    ];

    public function test_the_migration_moves_both_companies_to_the_manual_colors(): void
    {
        $segurex = Company::factory()->create(['slug' => 'segurex', 'color_primary' => '#FF671F', 'color_accent' => '#666666', 'color_dark' => '#8F360B']);
        $derivados = Company::factory()->create(['slug' => 'derivados', 'color_primary' => '#183F94', 'color_accent' => '#3E60A8', 'color_dark' => '#03256C']);
        $other = Company::factory()->create(['slug' => 'andina', 'color_primary' => '#0F8A7E']);

        $this->manualColorsMigration()->up();

        $this->assertSame(self::SEGUREX, $segurex->fresh()->only(array_keys(self::SEGUREX)));
        $this->assertSame(self::DERIVADOS, $derivados->fresh()->only(array_keys(self::DERIVADOS)));
        $this->assertSame('#0F8A7E', $other->fresh()->color_primary);
        $this->assertNull($other->fresh()->color_button);
    }

    public function test_rolling_back_restores_the_previous_colors(): void
    {
        $segurex = Company::factory()->create(['slug' => 'segurex', ...self::SEGUREX]);

        $this->manualColorsMigration()->down();

        $segurex = $segurex->fresh();
        $this->assertSame('#FF671F', $segurex->color_primary);
        $this->assertSame('#666666', $segurex->color_accent);
        $this->assertSame('#8F360B', $segurex->color_dark);
        $this->assertNull($segurex->color_button);
        $this->assertNull($segurex->color_text_dark_mode);
    }

    public function test_the_seeder_writes_the_manual_colors(): void
    {
        $this->seed(CompanySeeder::class);

        $segurex = Company::where('slug', 'segurex')->firstOrFail();
        $derivados = Company::where('slug', 'derivados')->firstOrFail();

        $this->assertSame(self::SEGUREX, $segurex->only(array_keys(self::SEGUREX)));
        $this->assertSame(self::DERIVADOS, $derivados->only(array_keys(self::DERIVADOS)));
    }

    public function test_the_segurex_page_is_painted_with_the_manual_tokens(): void
    {
        $css = BrandPalette::forCompany(new Company(self::SEGUREX))->toCss();

        $this->assertStringContainsString('--brand-accent:#F26522;', $css);
        $this->assertStringContainsString('--brand-button:#C6531C;', $css);
        $this->assertStringContainsString('--brand-ink:#943E15;', $css);
        $this->assertStringContainsString('--brand-text:#58595B;', $css);
        $this->assertStringContainsString('--brand-dark:#58595B;', $css);
        $this->assertStringContainsString('{:root{--brand-tint:', $css);
        $this->assertStringContainsString('--brand-ink:#F26522;--brand-text:#A8A9AA;}}', $css);
    }

    public function test_changing_only_the_primary_color_drops_the_manual_colors(): void
    {
        // The admin editor only edits primary, accent and dark: manual colors
        // picked for another primary would no longer match it.
        $company = Company::factory()->create(self::SEGUREX);

        $company->update(['color_primary' => '#0F8A7E']);

        $company = $company->fresh();
        $this->assertSame('#0F8A7E', $company->color_primary);
        $this->assertNull($company->color_button);
        $this->assertNull($company->color_ink);
        $this->assertNull($company->color_text);
        $this->assertNull($company->color_ink_dark_mode);
        $this->assertNull($company->color_text_dark_mode);
    }

    public function test_other_changes_keep_the_manual_colors(): void
    {
        $company = Company::factory()->create(self::SEGUREX);

        $company->update(['name' => 'Segurex Latam', 'color_primary' => '#F26522', 'color_dark' => '#58595B']);

        $this->assertSame(self::SEGUREX, $company->fresh()->only(array_keys(self::SEGUREX)));
    }

    private function manualColorsMigration(): Migration
    {
        return require base_path(self::MANUAL_COLORS);
    }
}
