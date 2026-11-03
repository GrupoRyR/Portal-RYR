<?php

namespace Tests\Feature\Admin;

use App\Audit\AuditPresenter;
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
 * The all-staff list and the sender mailbox of a company in Admin › Empresas
 * (odd/tasks/inicio-fase-2.md WU4): data, not code, managed by the super
 * admin only; both at one of the company's own domains, both or neither.
 */
class CompanyListEmailTest extends TestCase
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
            'domains' => ['segurex.com', 'segurexlatam.com'],
            'color_primary' => '#FF671F',
            'color_accent' => '#666666',
            'color_dark' => '#8F360B',
        ]);
        Company::factory()->create(['name' => 'Soluciones y Derivados', 'domains' => ['derivadoscol.com']]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    private function editor(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(CompanyEditor::class, ['company' => $this->company]);
    }

    public function test_it_saves_both_addresses_trimmed_and_lowercase_and_audits_them(): void
    {
        $this->editor()
            ->assertSeeText('Lista de todo el personal')
            ->assertSeeText('Buzón remitente')
            ->set('allStaffEmail', '  Todos@Segurex.com ')
            ->set('mailSender', 'Segurex.Info@segurex.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->company->refresh();
        $this->assertSame('todos@segurex.com', $this->company->all_staff_email);
        $this->assertSame('segurex.info@segurex.com', $this->company->mail_sender);

        $activity = Activity::query()->where('log_name', Company::AUDIT_LOG)->where('event', 'updated')->latest('id')->first();
        $entry = app(AuditPresenter::class)->activity($activity);
        $this->assertContains(['label' => 'Lista de todo el personal', 'old' => '—', 'new' => 'todos@segurex.com'], $entry->changes);
        $this->assertContains(['label' => 'Buzón remitente', 'old' => '—', 'new' => 'segurex.info@segurex.com'], $entry->changes);
    }

    public function test_it_loads_the_saved_addresses_and_clears_both(): void
    {
        $this->company->update(['all_staff_email' => 'todos@segurex.com', 'mail_sender' => 'segurex.info@segurex.com']);

        $this->editor()
            ->assertSet('allStaffEmail', 'todos@segurex.com')
            ->assertSet('mailSender', 'segurex.info@segurex.com')
            ->set('allStaffEmail', '')
            ->set('mailSender', ' ')
            ->call('save')
            ->assertHasNoErrors();

        $this->company->refresh();
        $this->assertNull($this->company->all_staff_email);
        $this->assertNull($this->company->mail_sender);
    }

    public function test_both_addresses_or_neither(): void
    {
        $this->editor()
            ->set('allStaffEmail', 'todos@segurex.com')
            ->call('save')
            ->assertHasErrors(['mailSender' => 'required_with'])
            ->set('allStaffEmail', '')
            ->set('mailSender', 'segurex.info@segurex.com')
            ->call('save')
            ->assertHasErrors(['allStaffEmail' => 'required_with']);

        $this->assertNull($this->company->fresh()->mail_sender);
    }

    public function test_both_must_be_at_one_of_the_company_domains(): void
    {
        $this->editor()
            ->set('allStaffEmail', 'todos@derivadoscol.com')
            ->set('mailSender', 'info@segurex.com.evil.example')
            ->call('save')
            ->assertHasErrors(['allStaffEmail', 'mailSender'])
            ->assertSeeText('Usa una dirección de uno de los dominios de la empresa: segurex.com, segurexlatam.com.');

        $this->assertNull($this->company->fresh()->all_staff_email);
    }

    public function test_a_subdomain_counts_as_another_domain(): void
    {
        $this->editor()
            ->set('allStaffEmail', 'todos@listas.segurex.com')
            ->set('mailSender', 'segurex.info@segurex.com')
            ->call('save')
            ->assertHasErrors(['allStaffEmail']);
    }

    public function test_the_domains_in_the_form_are_the_ones_that_count(): void
    {
        $this->company->update(['all_staff_email' => 'todos@segurex.com', 'mail_sender' => 'segurex.info@segurex.com']);

        // Removing segurex.com leaves both addresses outside the company.
        $this->editor()
            ->call('removeDomain', 0)
            ->call('save')
            ->assertHasErrors(['allStaffEmail', 'mailSender']);

        $this->assertSame(['segurex.com', 'segurexlatam.com'], $this->company->fresh()->domains);
    }

    public function test_they_must_be_valid_and_different_addresses(): void
    {
        $this->editor()
            ->set('allStaffEmail', 'todos segurex.com')
            ->set('mailSender', 'segurex.info@segurex.com')
            ->call('save')
            ->assertHasErrors(['allStaffEmail' => 'email'])
            ->set('allStaffEmail', 'segurex.info@segurex.com')
            ->call('save')
            ->assertHasErrors(['mailSender' => 'different']);
    }

    public function test_only_the_super_admin_manages_them(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('portal_admin');

        $this->editor($admin)->assertForbidden();
    }
}
