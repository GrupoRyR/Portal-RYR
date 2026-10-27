<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\CompanyEditor;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Brand manual colors (button, ink, text and their dark-mode values) in the
 * company editor: shown in the preview, kept when the primary changes and
 * only dropped when the admin asks for it.
 */
class CompanyEditorManualColorsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'slug' => 'segurex',
            'domains' => ['segurex.com'],
            'color_primary' => '#F26522',
            'color_accent' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#C6531C',
            'color_ink' => '#943E15',
            'color_text' => '#58595B',
            'color_ink_dark_mode' => '#F26522',
            'color_text_dark_mode' => '#A8A9AA',
            'quick_links' => null,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->admin)->test(CompanyEditor::class, ['company' => $this->company]);
    }

    public function test_the_preview_paints_the_manual_colors(): void
    {
        $this->editor()
            ->assertSee('--brand-button:#C6531C', false)
            ->assertSee('--brand-ink:#943E15', false)
            ->assertSee('Colores del manual de marca');
    }

    public function test_a_new_primary_keeps_the_manual_colors_and_says_so(): void
    {
        $this->editor()
            ->set('colorPrimary', '#0F8A7E')
            ->assertSee('--brand-button:#C6531C', false)
            ->assertSee('siguen mandando')
            ->call('save')
            ->assertHasNoErrors();

        $company = $this->company->fresh();
        $this->assertSame('#0F8A7E', $company->color_primary);
        $this->assertSame('#C6531C', $company->color_button);
        $this->assertSame('#A8A9AA', $company->color_text_dark_mode);
    }

    public function test_the_admin_can_drop_the_manual_colors(): void
    {
        $this->editor()
            ->set('dropManualColors', true)
            ->assertDontSee('--brand-button:#C6531C', false)
            ->call('save')
            ->assertHasNoErrors();

        $company = $this->company->fresh();
        foreach (Company::MANUAL_COLORS as $attribute) {
            $this->assertNull($company->{$attribute}, $attribute);
        }

        $activity = Activity::query()->where('log_name', Company::AUDIT_LOG)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('#C6531C', $activity->properties['old']['color_button']);
        $this->assertNull($activity->properties['attributes']['color_button']);
    }

    public function test_companies_without_manual_colors_show_no_notice(): void
    {
        $this->company->update(array_fill_keys(Company::MANUAL_COLORS, null));

        $this->editor()->assertDontSee('Colores del manual de marca');
    }
}
