<?php

namespace Tests\Feature\Admin;

use App\Auth\RecentMicrosoftLogin;
use App\Livewire\Admin\CompanyWizard;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyChangedNotice;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CompanyWizardTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_TENANT = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    private const SEGUREX_TENANT = '97cd024c-cd7a-419b-b385-f47b5f5816c8';

    private const TOKEN_URL = 'https://login.microsoftonline.com/'.self::NEW_TENANT.'/oauth2/v2.0/token';

    private User $admin;

    private Company $segurex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Notification::fake();
        Http::preventStrayRequests();
        config([
            'services.microsoft.client_id' => 'aaaabbbb-0000-cccc-1111-dddd2222eeee',
            'services.microsoft.client_secret' => 'secreto',
            'services.microsoft.redirect' => 'https://portal.example.com/auth/microsoft/callback',
        ]);

        $this->segurex = Company::factory()->forTenant(self::SEGUREX_TENANT, ['segurex.com'])->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->admin = User::factory()->microsoft($this->segurex)->create(['name' => 'Jhon Castro']);
        $this->admin->assignRole('super_admin');
    }

    private function fresh(): static
    {
        session()->put(RecentMicrosoftLogin::SESSION_KEY, now()->getTimestamp());

        return $this->withSession([RecentMicrosoftLogin::SESSION_KEY => now()->getTimestamp()]);
    }

    private function wizard(?Company $company = null): Testable
    {
        $this->fresh();

        return Livewire::actingAs($this->admin)->test(CompanyWizard::class, $company ? ['company' => $company] : []);
    }

    private function fillStepOne(Testable $wizard, array $overrides = []): Testable
    {
        $values = array_merge([
            'name' => 'Andina Logística',
            'shortName' => 'Andina',
            'tenantId' => self::NEW_TENANT,
            'domains' => ['andinalogistica.com'],
            'colorPrimary' => '#0F8A7E',
            'colorAccent' => '#0B6B62',
            'colorDark' => '#0A4F48',
        ], $overrides);

        foreach ($values as $property => $value) {
            $wizard->set($property, $value);
        }

        return $wizard;
    }

    private function pendingCompany(array $attributes = []): Company
    {
        return Company::factory()->forTenant(self::NEW_TENANT, ['andinalogistica.com'])->create(array_merge([
            'name' => 'Andina Logística',
            'short_name' => 'Andina',
            'slug' => 'andina-logistica',
            'is_active' => false,
            'consent_verified_at' => null,
        ], $attributes));
    }

    public function test_only_super_admins_with_a_recent_microsoft_login_open_it(): void
    {
        $portalAdmin = User::factory()->microsoft($this->segurex)->create();
        $portalAdmin->assignRole('portal_admin');

        $this->actingAs($this->admin)->get(route('admin.companies.create'))
            ->assertRedirect(route('auth.microsoft.confirm', ['return' => '/admin/empresas/nueva']));

        $this->fresh()->actingAs($portalAdmin)->get(route('admin.companies.create'))->assertForbidden();

        $this->fresh()->actingAs($this->admin)->get(route('admin.companies.create'))
            ->assertOk()
            ->assertSee('Nueva empresa')
            ->assertSee('Datos y marca')
            ->assertSee('Esto abre la puerta a toda la organización');
    }

    public function test_emergency_accounts_cannot_create_companies(): void
    {
        $emergency = User::factory()->emergency()->create();
        $emergency->assignRole('super_admin');

        $this->actingAs($emergency)->get(route('admin.companies.create'))
            ->assertForbidden()
            ->assertSee('Crear empresas o cambiar su inquilino requiere iniciar sesión con Microsoft.');
    }

    public function test_the_companies_list_links_to_the_wizard(): void
    {
        $this->actingAs($this->admin)->get(route('admin.companies.index'))
            ->assertSee('href="'.route('admin.companies.create').'"', false);
    }

    public function test_the_slug_follows_the_name_until_edited(): void
    {
        $this->wizard()
            ->set('name', 'Andina Logística S.A.S.')
            ->assertSet('slug', 'andina-logistica-sas')
            ->set('slug', 'andina')
            ->set('name', 'Andina Otra')
            ->assertSet('slug', 'andina');
    }

    public function test_step_one_validates_tenant_slug_and_domains(): void
    {
        $this->fillStepOne($this->wizard(), ['tenantId' => 'no-es-un-guid'])->call('saveCompany')->assertHasErrors(['tenantId']);
        $this->fillStepOne($this->wizard(), ['tenantId' => '{'.self::NEW_TENANT.'}'])->call('saveCompany')->assertHasErrors(['tenantId']);
        $this->fillStepOne($this->wizard(), ['slug' => 'segurex'])->call('saveCompany')->assertHasErrors(['slug']);
        $this->fillStepOne($this->wizard(), ['slug' => 'nueva'])->call('saveCompany')->assertHasErrors(['slug']);
        $this->fillStepOne($this->wizard(), ['slug' => 'Mal Slug'])->call('saveCompany')->assertHasErrors(['slug']);
        $this->fillStepOne($this->wizard(), ['domains' => ['segurex.com']])->call('saveCompany')->assertHasErrors(['domains.0']);
        $this->fillStepOne($this->wizard(), ['colorPrimary' => '#fff;}'])->call('saveCompany')->assertHasErrors(['colorPrimary']);

        $this->assertSame(1, Company::count());
    }

    public function test_an_uppercase_tenant_is_stored_lowercase(): void
    {
        $this->fillStepOne($this->wizard(), ['tenantId' => strtoupper(self::NEW_TENANT)])->call('saveCompany')->assertHasNoErrors();

        $this->assertSame(self::NEW_TENANT, Company::where('slug', 'andina-logistica')->value('tenant_id'));
    }

    public function test_a_new_company_is_created_inactive_audited_and_announced(): void
    {
        $this->fillStepOne($this->wizard())
            ->call('saveCompany')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.companies.wizard', 'andina-logistica'));

        $company = Company::where('slug', 'andina-logistica')->sole();
        $this->assertFalse($company->is_active);
        $this->assertNull($company->consent_verified_at);
        $this->assertTrue($company->is_primary_for_tenant);
        $this->assertSame(['andinalogistica.com'], $company->domains);
        $this->assertSame('#0F8A7E', $company->color_primary);

        $activity = Activity::where('event', 'company.created')->whereMorphedTo('subject', $company)->sole();
        $this->assertTrue($activity->causer->is($this->admin));

        Notification::assertSentTo($this->admin, CompanyChangedNotice::class, fn (CompanyChangedNotice $notice) => $notice->event === CompanyChangedNotice::CREATED);
    }

    public function test_creating_needs_a_recent_microsoft_login(): void
    {
        $wizard = $this->fillStepOne($this->wizard());
        session()->forget(RecentMicrosoftLogin::SESSION_KEY);

        $wizard->call('saveCompany')->assertRedirect(route('auth.microsoft.confirm', ['return' => '/admin/empresas/nueva']));

        $this->assertSame(1, Company::count());
    }

    public function test_the_primary_choice_appears_only_for_a_tenant_in_use_and_respects_the_rule(): void
    {
        $this->fillStepOne($this->wizard())->assertDontSee('Empresa principal de este inquilino');

        $wizard = $this->fillStepOne($this->wizard(), ['tenantId' => self::SEGUREX_TENANT])
            ->assertSee('Empresa principal de este inquilino')
            ->set('isPrimary', true)
            ->call('saveCompany')
            ->assertHasErrors(['isPrimary']);

        $wizard->set('isPrimary', false)->call('saveCompany')->assertHasNoErrors();

        $company = Company::where('slug', 'andina-logistica')->sole();
        $this->assertFalse($company->is_primary_for_tenant);
        $this->assertSame(self::SEGUREX_TENANT, $company->tenant_id);
    }

    public function test_an_unfinished_company_is_listed_with_its_step_and_resumes_at_consent(): void
    {
        $company = $this->pendingCompany();

        $this->actingAs($this->admin)->get(route('admin.companies.index'))
            ->assertSee('Paso 2 de 4')
            ->assertSee('href="'.route('admin.companies.wizard', $company).'"', false)
            ->assertSee('Continuar');

        $this->fresh()->actingAs($this->admin)->get(route('admin.companies.wizard', $company))
            ->assertOk()
            ->assertSee('Pide el consentimiento del administrador de Microsoft')
            ->assertSee('https://login.microsoftonline.com/'.self::NEW_TENANT.'/adminconsent?client_id=aaaabbbb-0000-cccc-1111-dddd2222eeee', false)
            ->assertSee('wire:poll.60s="checkConsent"', false);
    }

    public function test_an_active_company_is_not_reopened_in_the_wizard(): void
    {
        $this->fresh()->actingAs($this->admin)->get(route('admin.companies.wizard', $this->segurex))
            ->assertRedirect(route('admin.companies.show', $this->segurex));
    }

    public function test_polling_marks_consent_as_verified(): void
    {
        $company = $this->pendingCompany();
        Http::fake([self::TOKEN_URL => Http::response(['token_type' => 'Bearer', 'access_token' => 'eyJ.x.y'])]);

        $this->wizard($company)
            ->assertSet('step', 2)
            ->call('checkConsent')
            ->assertSee('Consentimiento verificado')
            ->assertDontSee('wire:poll.60s="checkConsent"', false);

        $this->assertNotNull($company->fresh()->consent_verified_at);
        $this->assertSame(1, Activity::where('event', 'company.consent_verified')->count());
    }

    public function test_a_pending_consent_keeps_waiting(): void
    {
        $company = $this->pendingCompany();
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'unauthorized_client', 'error_codes' => [700016]], 400)]);

        $this->wizard($company)
            ->call('checkConsent')
            ->assertSee('Esperando el consentimiento')
            ->assertSee('Última revisión')
            ->call('goToStep', 3)
            ->assertSet('step', 2);

        $this->assertNull($company->fresh()->consent_verified_at);
    }

    public function test_an_inconclusive_check_never_verifies(): void
    {
        $company = $this->pendingCompany();
        config(['services.microsoft.client_secret' => null]);

        $this->wizard($company)
            ->call('checkConsent')
            ->assertSee('No se pudo comprobar');

        $this->assertNull($company->fresh()->consent_verified_at);
    }

    public function test_activation_is_blocked_until_consent_is_verified(): void
    {
        $company = $this->pendingCompany();

        $this->wizard($company)->call('activate')->assertHasErrors(['activation']);
        $this->assertFalse($company->fresh()->is_active);

        $company->update(['consent_verified_at' => now()]);

        $this->wizard($company->fresh())
            ->assertSet('step', 3)
            ->assertDontSee('APP-PortalAdmin')
            ->assertSee('Un grupo de seguridad por cada aplicación del catálogo')
            ->assertSee('Los administradores del portal no se crean aquí')
            ->assertSee('crear-grupos-app.ps1 -TenantId '.self::NEW_TENANT." -Grupos 'APP-Cartera'", false)
            ->assertDontSee("-Grupos 'APP-&lt;App&gt;'", false)
            ->call('goToStep', 4)
            ->assertSet('step', 4)
            ->call('activate')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.companies.show', $company));

        $this->assertTrue($company->fresh()->is_active);
        Notification::assertSentTo($this->admin, CompanyChangedNotice::class, fn (CompanyChangedNotice $notice) => $notice->event === CompanyChangedNotice::ACTIVATED);
    }

    public function test_the_admin_consent_answer_lands_on_a_spanish_page(): void
    {
        $this->get('/auth/microsoft/callback?admin_consent=True&tenant='.self::NEW_TENANT.'&state=admin-consent')
            ->assertOk()
            ->assertSee('Consentimiento registrado');

        $this->get('/auth/microsoft/callback?error=access_denied&error_description=The+admin+canceled&state=admin-consent')
            ->assertOk()
            ->assertSee('No se completó el consentimiento');
    }

    public function test_portal_admins_cannot_use_the_wizard_component(): void
    {
        $portalAdmin = User::factory()->microsoft($this->segurex)->create();
        $portalAdmin->assignRole('portal_admin');
        $this->fresh();

        Livewire::actingAs($portalAdmin)->test(CompanyWizard::class)->assertForbidden();
        Livewire::actingAs($portalAdmin)->test(CompanyWizard::class, ['company' => $this->pendingCompany()])->assertForbidden();
    }
}
