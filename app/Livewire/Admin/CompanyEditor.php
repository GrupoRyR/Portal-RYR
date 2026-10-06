<?php

namespace App\Livewire\Admin;

use App\Branding\BrandPalette;
use App\Branding\Contrast;
use App\Models\Company;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Edits one company (brief 5.1): names, email domains, quick links, brand
 * colors with a live contrast check and preview, and the active switch. Slug and tenant are read-only here (tenant changes are T6c).
 */
class CompanyEditor extends Component
{
    use AuthorizesRequests;

    public const MAX_QUICK_LINKS = 8;

    /** Lowercase host name: labels of 1–63 characters and a 2–63 letter TLD. */
    public const DOMAIN_PATTERN = '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/';

    private const HEX_PATTERN = '/\A#[0-9A-Fa-f]{6}\z/';

    private const PREVIEWS = ['light', 'dark', 'phone'];

    #[Locked]
    public Company $company;

    public string $name = '';

    public string $shortName = '';

    /** @var list<string> */
    public array $domains = [];

    public string $newDomain = '';

    /** @var list<array{label: string, url: string}> */
    public array $quickLinks = [];

    public string $colorPrimary = '';

    public string $colorAccent = '';

    public string $colorDark = '';

    public bool $isActive = true;

    public string $preview = 'light';

    #[Locked]
    public bool $confirmingDeactivation = false;

    public function mount(Company $company): void
    {
        $this->authorize('update', $company);

        $this->company = $company;
        $this->name = $company->name;
        $this->shortName = $company->short_name;
        $this->domains = array_values($company->domains ?? []);
        $this->quickLinks = array_values(array_map(
            fn (array $link) => ['label' => (string) ($link['label'] ?? ''), 'url' => (string) ($link['url'] ?? '')],
            $company->quick_links ?? [],
        ));
        $this->colorPrimary = $company->color_primary;
        $this->colorAccent = $company->color_accent;
        $this->colorDark = $company->color_dark;
        $this->isActive = $company->is_active;
    }

    public function addDomain(): void
    {
        $this->authorize('update', $this->company);

        $this->newDomain = strtolower(trim($this->newDomain));

        $this->validateOnly('newDomain', [
            'newDomain' => ['required', 'string', 'regex:'.self::DOMAIN_PATTERN, $this->domainIsFree(...), function (string $attribute, string $value, Closure $fail) {
                if (in_array($value, $this->domains, true)) {
                    $fail('Este dominio ya está en la lista.');
                }
            }],
        ], $this->messages());

        $this->domains[] = $this->newDomain;
        $this->newDomain = '';
    }

    public function removeDomain(int $index): void
    {
        unset($this->domains[$index]);
        $this->domains = array_values($this->domains);
    }

    public function addQuickLink(): void
    {
        if (count($this->quickLinks) < self::MAX_QUICK_LINKS) {
            $this->quickLinks[] = ['label' => '', 'url' => 'https://'];
        }
    }

    public function removeQuickLink(int $index): void
    {
        unset($this->quickLinks[$index]);
        $this->quickLinks = array_values($this->quickLinks);
    }

    public function setPreview(string $mode): void
    {
        if (in_array($mode, self::PREVIEWS, true)) {
            $this->preview = $mode;
        }
    }

    public function save(): mixed
    {
        $this->authorize('update', $this->company);

        $this->validate();
        $this->guardOwnCompany();

        // Turning a company off locks out all its users: ask first.
        if ($this->company->is_active && ! $this->isActive && ! $this->confirmingDeactivation) {
            $this->confirmingDeactivation = true;

            return null;
        }

        return $this->persist();
    }

    public function confirmDeactivation(): mixed
    {
        $this->authorize('update', $this->company);

        $this->isActive = false;
        $this->confirmingDeactivation = true;

        return $this->save();
    }

    public function cancelDeactivation(): void
    {
        $this->confirmingDeactivation = false;
        $this->isActive = true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'shortName' => ['required', 'string', 'max:40'],
            'domains' => ['required', 'array', 'min:1', 'max:20'],
            'domains.*' => ['required', 'string', 'distinct', 'regex:'.self::DOMAIN_PATTERN, $this->domainIsFree(...)],
            'quickLinks' => ['array', 'max:'.self::MAX_QUICK_LINKS],
            'quickLinks.*.label' => ['required', 'string', 'max:40'],
            'quickLinks.*.url' => ['required', 'string', 'max:2048', 'url:https', 'starts_with:https://'],
            'colorPrimary' => ['required', 'string', 'regex:'.self::HEX_PATTERN],
            'colorAccent' => ['required', 'string', 'regex:'.self::HEX_PATTERN],
            'colorDark' => ['required', 'string', 'regex:'.self::HEX_PATTERN],
            'isActive' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'max' => 'Es demasiado largo.',
            'name.max' => 'Usa como máximo 120 caracteres.',
            'shortName.max' => 'Usa como máximo 40 caracteres.',
            'domains.required' => 'Agrega al menos un dominio de correo.',
            'domains.min' => 'Agrega al menos un dominio de correo.',
            'domains.max' => 'Puedes registrar hasta 20 dominios.',
            'domains.*.distinct' => 'El dominio está repetido.',
            'domains.*.regex' => 'Escribe un dominio válido en minúsculas, por ejemplo segurex.com.',
            'newDomain.required' => 'Escribe un dominio.',
            'newDomain.regex' => 'Escribe un dominio válido, por ejemplo segurex.com (sin https:// ni @).',
            'quickLinks.max' => 'Puedes tener hasta 8 enlaces rápidos.',
            'quickLinks.*.label.required' => 'Escribe el nombre del enlace.',
            'quickLinks.*.label.max' => 'Usa como máximo 40 caracteres.',
            'quickLinks.*.url.required' => 'Escribe la dirección del enlace.',
            'quickLinks.*.url.url' => 'Escribe una dirección completa que empiece por https://.',
            'quickLinks.*.url.starts_with' => 'Escribe una dirección completa que empiece por https://.',
            'colorPrimary.regex' => 'Usa el formato #RRGGBB, por ejemplo #FF671F.',
            'colorAccent.regex' => 'Usa el formato #RRGGBB, por ejemplo #666666.',
            'colorDark.regex' => 'Usa el formato #RRGGBB, por ejemplo #8F360B.',
        ];
    }

    public function render(): View
    {
        $palette = BrandPalette::fromColors($this->colorPrimary, $this->colorAccent, $this->colorDark);
        $primaryIsValid = preg_match(self::HEX_PATTERN, $this->colorPrimary) === 1;

        return view('livewire.admin.company-editor', [
            'palette' => $palette,
            'primaryRatio' => $primaryIsValid ? Contrast::ratio('#FFFFFF', $this->colorPrimary) : null,
            'buttonRatio' => Contrast::ratio('#FFFFFF', $palette->button),
        ]);
    }

    private function persist(): mixed
    {
        $company = $this->company;
        DB::transaction(function () use ($company) {
            $company->fill([
                'name' => trim($this->name),
                'short_name' => trim($this->shortName),
                'domains' => $this->domains,
                'quick_links' => $this->quickLinks === [] ? null : array_map(
                    fn (array $link) => ['label' => trim($link['label']), 'url' => trim($link['url'])],
                    $this->quickLinks,
                ),
                'color_primary' => strtoupper($this->colorPrimary),
                'color_accent' => strtoupper($this->colorAccent),
                'color_dark' => strtoupper($this->colorDark),
                'is_active' => $this->isActive,
            ])->save();
        });

        session()->flash('status', 'Cambios guardados.');

        return $this->redirectRoute('admin.companies.show', $company->slug);
    }

    private function guardOwnCompany(): void
    {
        if ($this->isActive || Auth::user()?->company_id !== $this->company->id) {
            return;
        }

        $this->confirmingDeactivation = false;

        throw ValidationException::withMessages([
            'isActive' => 'No puedes desactivar la empresa con la que iniciaste sesión.',
        ]);
    }

    private function domainIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $owner = Company::domainOwner($value, $this->company->id);

        if ($owner !== null) {
            $fail("Este dominio ya pertenece a {$owner->name}.");
        }
    }
}
