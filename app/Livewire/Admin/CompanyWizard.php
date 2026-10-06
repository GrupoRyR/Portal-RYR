<?php

namespace App\Livewire\Admin;

use App\Auth\RecentMicrosoftLogin;
use App\Branding\BrandPalette;
use App\Microsoft\ConsentChecker;
use App\Models\Company;
use App\Notifications\CompanyAdminNotifier;
use App\Notifications\CompanyChangedNotice;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New-company wizard (docs/diseno/AdminWizard.dc.html):
 * 1. data and brand → creates the company, inactive and without consent;
 * 2. admin consent → checked every minute and on demand (ConsentChecker);
 * 3. APP-… groups to create in the tenant;
 * 4. activation, only once consent is verified.
 */
class CompanyWizard extends Component
{
    use AuthorizesRequests;

    public const TENANT_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** Slugs that would collide with fixed admin paths. */
    public const RESERVED_SLUGS = ['nueva'];

    #[Locked]
    public ?Company $company = null;

    #[Locked]
    public int $step = 1;

    public string $name = '';

    public string $shortName = '';

    public string $slug = '';

    #[Locked]
    public bool $slugEdited = false;

    public string $tenantId = '';

    /** @var list<string> */
    public array $domains = [];

    public string $newDomain = '';

    public string $colorPrimary = '#17191C';

    public string $colorAccent = '#5E6168';

    public string $colorDark = '#17191C';

    public bool $isPrimary = true;

    /** Result of the last consent check: pending, unknown or granted. */
    #[Locked]
    public ?string $consentStatus = null;

    #[Locked]
    public ?int $lastCheckedAt = null;

    public function mount(?Company $company = null): void
    {
        if ($company === null) {
            $this->authorize('create', Company::class);

            return;
        }

        $this->authorize('update', $company);

        $this->company = $company;
        $this->step = $company->consent_verified_at === null ? 2 : 3;
    }

    public function updatedName(): void
    {
        if (! $this->slugEdited) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugEdited = true;
        $this->slug = Str::lower(trim($this->slug));
    }

    public function updatedTenantId(): void
    {
        $this->tenantId = Str::lower(trim($this->tenantId));
    }

    public function addDomain(): void
    {
        $this->newDomain = Str::lower(trim($this->newDomain));

        $this->validateOnly('newDomain', [
            'newDomain' => ['required', 'regex:'.CompanyEditor::DOMAIN_PATTERN, $this->domainIsFree(...), Rule::notIn($this->domains)],
        ], [
            'newDomain.required' => 'Escribe un dominio.',
            'newDomain.regex' => 'Escribe un dominio válido, por ejemplo andina.com (sin https:// ni @).',
            'newDomain.not_in' => 'Este dominio ya está en la lista.',
        ]);

        $this->domains[] = $this->newDomain;
        $this->newDomain = '';
    }

    public function removeDomain(int $index): void
    {
        unset($this->domains[$index]);
        $this->domains = array_values($this->domains);
    }

    /**
     * Step 1: creates the company, inactive and without consent.
     */
    public function saveCompany(RecentMicrosoftLogin $recent, CompanyAdminNotifier $notifier): mixed
    {
        $this->authorize('create', Company::class);

        if ($this->company !== null) {
            return null;
        }

        if (! $recent->isFresh()) {
            return $this->redirectRoute('auth.microsoft.confirm', ['return' => route('admin.companies.create', [], false)]);
        }

        $this->tenantId = Str::lower(trim($this->tenantId));
        $this->slug = Str::lower(trim($this->slug));
        $sharedTenant = $this->tenantInUse();

        $this->validate($this->stepOneRules(), $this->stepOneMessages());

        if ($sharedTenant && $this->isPrimary && ($primary = $this->tenantPrimary()) !== null) {
            throw ValidationException::withMessages([
                'isPrimary' => "Este inquilino ya tiene una empresa principal: {$primary->name}. Solo puede haber una.",
            ]);
        }

        $company = DB::transaction(fn () => Company::create([
            'name' => trim($this->name),
            'short_name' => trim($this->shortName),
            'slug' => $this->slug,
            'tenant_id' => $this->tenantId,
            'is_primary_for_tenant' => $sharedTenant ? $this->isPrimary : true,
            'domains' => $this->domains,
            'color_primary' => Str::upper($this->colorPrimary),
            'color_accent' => Str::upper($this->colorAccent),
            'color_dark' => Str::upper($this->colorDark),
            'is_active' => false,
            'consent_verified_at' => null,
        ]));

        $notifier->notify($company, CompanyChangedNotice::CREATED, Auth::user());

        return $this->redirectRoute('admin.companies.wizard', $company);
    }

    /**
     * Step 2: asks Microsoft whether the tenant's admin consented. Runs on
     * wire:poll every 60 s and from «Comprobar ahora».
     */
    public function checkConsent(ConsentChecker $checker): void
    {
        $company = $this->requireCompany();

        if ($company->consent_verified_at !== null) {
            $this->consentStatus = ConsentChecker::GRANTED;

            return;
        }

        $this->consentStatus = $checker->check($company->tenant_id);
        $this->lastCheckedAt = now()->getTimestamp();

        if ($this->consentStatus === ConsentChecker::GRANTED) {
            $company->update(['consent_verified_at' => now()]);
        }
    }

    public function goToStep(int $step): void
    {
        $company = $this->requireCompany();
        $highest = $company->consent_verified_at === null ? 2 : 4;

        if ($step >= 2 && $step <= $highest) {
            $this->step = $step;
        }
    }

    /**
     * Step 4: opens the portal to the tenant's users.
     */
    public function activate(CompanyAdminNotifier $notifier): mixed
    {
        $company = $this->requireCompany();

        if ($company->consent_verified_at === null) {
            throw ValidationException::withMessages([
                'activation' => 'Primero debe llegar el consentimiento del administrador de Microsoft.',
            ]);
        }

        DB::transaction(fn () => $company->update(['is_active' => true]));
        $notifier->notify($company, CompanyChangedNotice::ACTIVATED, Auth::user());

        session()->flash('status', "{$company->name} quedó activa. Sus usuarios ya pueden entrar.");

        return $this->redirectRoute('admin.companies.show', $company);
    }

    public function render(): View
    {
        $company = $this->company?->fresh() ?? $this->company;
        $palette = $company
            ? BrandPalette::forCompany($company)
            : BrandPalette::fromColors($this->colorPrimary, $this->colorAccent, $this->colorDark);

        return view('livewire.admin.company-wizard', [
            'current' => $company,
            'palette' => $palette,
            'showPrimaryChoice' => $company === null && $this->tenantInUse(),
            'consentUrl' => $company ? app(ConsentChecker::class)->adminConsentUrl($company->tenant_id) : null,
            'adminGroup' => (string) config('portal.admin_group'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepOneRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'shortName' => ['required', 'string', 'max:40'],
            'slug' => ['required', 'string', 'max:60', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::notIn(self::RESERVED_SLUGS), Rule::unique('companies', 'slug')],
            'tenantId' => ['required', 'string', 'regex:'.self::TENANT_PATTERN],
            'domains' => ['required', 'array', 'min:1', 'max:20'],
            'domains.*' => ['required', 'string', 'distinct', 'regex:'.CompanyEditor::DOMAIN_PATTERN, $this->domainIsFree(...)],
            'colorPrimary' => ['required', 'string', 'regex:'.CompanyEditor::HEX_PATTERN],
            'colorAccent' => ['required', 'string', 'regex:'.CompanyEditor::HEX_PATTERN],
            'colorDark' => ['required', 'string', 'regex:'.CompanyEditor::HEX_PATTERN],
            'isPrimary' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function stepOneMessages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'max' => 'Es demasiado largo.',
            'slug.regex' => 'Usa solo minúsculas, números y guiones, por ejemplo andina-logistica.',
            'slug.not_in' => 'Ese identificador está reservado. Elige otro.',
            'slug.unique' => 'Ya existe una empresa con ese identificador.',
            'tenantId.regex' => 'Pega el ID de inquilino (GUID) tal como aparece en Microsoft Entra, por ejemplo 54f53029-e6e2-4b5f-a862-3454ae886468.',
            'domains.required' => 'Agrega al menos un dominio de correo.',
            'domains.min' => 'Agrega al menos un dominio de correo.',
            'domains.*.distinct' => 'El dominio está repetido.',
            'domains.*.regex' => 'Escribe un dominio válido en minúsculas.',
            'colorPrimary.regex' => 'Usa el formato #RRGGBB.',
            'colorAccent.regex' => 'Usa el formato #RRGGBB.',
            'colorDark.regex' => 'Usa el formato #RRGGBB.',
        ];
    }

    private function requireCompany(): Company
    {
        $company = $this->company?->fresh() ?? abort(404);

        $this->authorize('update', $company);

        if ($company->is_active) {
            abort(409);
        }

        $this->company = $company;

        return $company;
    }

    private function tenantInUse(): bool
    {
        return preg_match(self::TENANT_PATTERN, $this->tenantId) === 1
            && Company::query()->where('tenant_id', $this->tenantId)->exists();
    }

    private function tenantPrimary(): ?Company
    {
        return Company::query()->where('tenant_id', $this->tenantId)->where('is_primary_for_tenant', true)->first();
    }

    private function domainIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ($owner = Company::domainOwner($value)) !== null) {
            $fail("Este dominio ya pertenece a {$owner->name}.");
        }
    }
}
