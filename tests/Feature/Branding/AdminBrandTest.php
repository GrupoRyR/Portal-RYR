<?php

namespace Tests\Feature\Branding;

use App\Livewire\Admin\CompanyWizard;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administration on the official brand manual SGX/SDC: the logo on a
 * --brand-dark band, labels in sentence case and filled buttons on
 * --brand-button.
 */
class AdminBrandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');
        $this->seed(RoleSeeder::class);

        $this->company = Company::factory()->create(['name' => 'Segurex Latam', 'logo_dark_path' => 'brand/segurex/logo-dark.png']);
        $this->admin = User::factory()->microsoft($this->company)->create();
        $this->admin->assignRole('super_admin');
    }

    public function test_the_shell_shows_the_logo_on_brand_dark_bands(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk()->getContent();

        // Phone top bar and the head of the sidebar, each with the logo.
        $this->assertMatchesRegularExpression('/<header class="brand-band [^"]*lg:hidden">.*?alt="Segurex Latam".*?<\/header>/s', $html);
        $this->assertMatchesRegularExpression('/<nav id="admin-sidebar".*?<div class="brand-band [^"]*">.*?alt="Segurex Latam"/s', $html);
        $this->assertStringNotContainsString('bg-brand-dark px-3', $html);
    }

    public function test_the_sidebar_band_does_not_rely_on_the_parent_padding(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk()->getContent();

        preg_match('/<nav id="admin-sidebar"[^>]*class="([^"]*)"/s', $html, $nav);
        preg_match('/<div class="(brand-band [^"]*)">/', $html, $band);

        $this->assertDoesNotMatchRegularExpression('/(^| )(px|py|p)-/', $nav[1]);
        $this->assertDoesNotMatchRegularExpression('/(^| )-m[xyt]-/', $band[1]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'apps' => ['admin.apps.index'],
            'categories' => ['admin.categories.index'],
            'audit' => ['admin.audit.index'],
            'integrations' => ['admin.integrations.index'],
            'roles' => ['admin.roles.index'],
            'usage' => ['admin.usage.index'],
            'announcements' => ['admin.announcements.index'],
            'companies' => ['admin.companies.index'],
            'company editor' => ['admin.companies.show'],
        ];
    }

    /**
     * Headings, labels and table header rows only: hex inputs keep
     * `uppercase` on purpose (they show #RRGGBB codes, not words).
     */
    #[DataProvider('pages')]
    public function test_labels_and_table_headers_are_in_sentence_case(string $route): void
    {
        $url = $route === 'admin.companies.show' ? route($route, $this->company) : route($route);

        $this->assertSentenceCase($this->actingAs($this->admin)->get($url)->assertOk()->getContent());
    }

    public function test_the_company_wizard_is_in_sentence_case(): void
    {
        // Its route also asks for a fresh Microsoft sign-in; the component
        // renders the same markup.
        $this->assertSentenceCase(Livewire::actingAs($this->admin)->test(CompanyWizard::class)->html());
    }

    private function assertSentenceCase(string $html): void
    {
        preg_match_all('~<(h1|h2|h3|th|tr|label|legend)\s[^>]*class="([^"]*)"~', $html, $elements);

        $this->assertNotEmpty($elements[0]);
        foreach ($elements[2] as $i => $classes) {
            $this->assertStringNotContainsString('uppercase', $classes, $elements[0][$i]);
        }
    }

    public function test_filled_buttons_use_the_brand_button_color(): void
    {
        foreach (['admin.audit.index', 'admin.categories.index'] as $route) {
            $this->actingAs($this->admin)->get(route($route))
                ->assertOk()
                ->assertSee('bg-brand-button', false)
                ->assertDontSee('bg-fg px-4 text-sm font-semibold text-on-fg', false);
        }
    }
}
