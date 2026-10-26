<?php

namespace Tests\Feature\Branding;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');
        $this->seed(RoleSeeder::class);

        $company = Company::factory()->create(['name' => 'Segurex Latam', 'logo_dark_path' => 'brand/segurex/logo-dark.png']);
        $this->admin = User::factory()->microsoft($company)->create();
        $this->admin->assignRole('super_admin');
    }

    public function test_the_shell_shows_the_logo_on_brand_dark_bands(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk();

        // Phone top bar and the head of the sidebar.
        $this->assertSame(2, substr_count($response->getContent(), 'brand-band'));
        $response->assertSee('alt="Segurex Latam"', false)
            ->assertDontSee('bg-brand-dark px-3', false);
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
        ];
    }

    #[DataProvider('pages')]
    public function test_labels_and_table_headers_are_in_sentence_case(string $route): void
    {
        $this->actingAs($this->admin)->get(route($route))
            ->assertOk()
            ->assertDontSee('uppercase', false);
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
