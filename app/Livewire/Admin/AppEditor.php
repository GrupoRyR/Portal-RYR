<?php

namespace App\Livewire\Admin;

use App\Catalog\AppIconStore;
use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use App\Rules\AllowedAppUrl;
use App\Settings\PortalSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Creates or edits one catalog app (brief §5.2,
 * docs/diseno/AdminAppEdit.dc.html): general data and companies, how it
 * opens, the status TI sets, the status reported by other systems and the
 * icon. Who sees the app is App\Livewire\Admin\AppAccessRules.
 */
class AppEditor extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    /** Lowercase words joined by single hyphens, e.g. cuentas-por-pagar. */
    public const SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** Slugs that would collide with fixed admin paths. */
    public const RESERVED_SLUGS = ['nueva'];

    public const HEX_PATTERN = '/\A#[0-9A-Fa-f]{6}\z/';

    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    public const WEB_HOSTINGS = ['godaddy', 'vercel', 'other'];

    /** Latest API reports listed under «Estado reportado». */
    public const STATUS_HISTORY = 5;

    public const WEB_LOGIN_TYPES = ['microsoft', 'own'];

    #[Locked]
    public ?App $app = null;

    public string $name = '';

    public string $slug = '';

    #[Locked]
    public bool $slugEdited = false;

    public string $description = '';

    public string $categoryId = '';

    /** @var list<int|string> */
    public array $companyIds = [];

    public bool $allCompanies = false;

    public string $type = App::TYPE_WEB;

    public string $url = 'https://';

    public string $openIn = App::OPEN_NEW;

    public string $hosting = 'godaddy';

    public string $loginType = 'microsoft';

    public string $localInstructions = '';

    public string $downloadUrl = '';

    public string $status = App::STATUS_ACTIVE;

    public string $maintenanceMessage = '';

    public string $maintenanceUntil = '';

    public string $accentColor = '#334155';

    public ?TemporaryUploadedFile $iconLight = null;

    public ?TemporaryUploadedFile $iconDark = null;

    public function mount(?App $app = null): void
    {
        if ($app === null || ! $app->exists) {
            $this->authorize('create', App::class);
            $this->categoryId = (string) (AppCategory::query()->ordered()->value('id') ?? '');

            return;
        }

        $this->authorize('update', $app);

        $this->app = $app;
        $this->name = $app->name;
        $this->slug = $app->slug;
        $this->description = $app->description;
        $this->categoryId = (string) $app->app_category_id;
        $this->companyIds = $app->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all();
        $this->allCompanies = $app->available_to_all_companies;
        $this->type = $app->type;
        $this->url = (string) ($app->url ?? 'https://');
        $this->openIn = $app->open_in;
        $this->hosting = $app->hosting;
        $this->loginType = $app->login_type;
        $this->localInstructions = (string) $app->local_instructions;
        $this->downloadUrl = (string) $app->download_url;
        $this->status = $app->status;
        $this->maintenanceMessage = (string) $app->maintenance_message;
        $this->maintenanceUntil = (string) $app->maintenance_until?->format(self::DATETIME_FORMAT);
        $this->accentColor = $app->accent_color;
    }

    /**
     * While creating, the slug follows the name until edited by hand.
     */
    public function updatedName(): void
    {
        if ($this->app === null && ! $this->slugEdited) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugEdited = true;
    }

    /**
     * Local apps are installed on the computer: no hosting or sign-in to
     * choose. Switching back to web picks sensible values again.
     */
    public function updatedType(): void
    {
        if ($this->type === App::TYPE_LOCAL) {
            $this->hosting = 'local';
            $this->loginType = 'local';

            return;
        }

        if (! in_array($this->hosting, self::WEB_HOSTINGS, true)) {
            $this->hosting = 'other';
        }

        if (! in_array($this->loginType, self::WEB_LOGIN_TYPES, true)) {
            $this->loginType = 'own';
        }
    }

    public function save(AppIconStore $icons): mixed
    {
        $this->app === null
            ? $this->authorize('create', App::class)
            : $this->authorize('update', $this->app);

        $this->name = trim($this->name);
        $this->url = trim($this->url);
        $this->downloadUrl = trim($this->downloadUrl);

        if ($this->app === null) {
            $this->slug = strtolower(trim($this->slug));
        }

        $this->validate();

        $app = $this->app ?? new App(['slug' => $this->slug]);
        $oldCompanies = $app->exists ? $this->companyNames($app->companies()->pluck('companies.id')->all()) : [];
        $old = [];
        $new = [];

        foreach (['iconLight' => 'light', 'iconDark' => 'dark'] as $property => $variant) {
            if ($this->{$property} !== null) {
                $attribute = AppIconStore::VARIANTS[$variant];
                $old[$attribute] = $app->{$attribute};
                $new[$attribute] = $icons->store($app->slug, $variant, $this->{$property});
            }
        }

        try {
            DB::transaction(function () use ($app, $new, $oldCompanies) {
                $app->fill([...$this->attributes(), ...$new])->save();

                $companyIds = array_map('intval', $this->companyIds);
                $app->syncCompanies($companyIds);

                $newCompanies = $this->companyNames($companyIds);

                if ($newCompanies !== $oldCompanies) {
                    $app->audit('app.companies_changed', [
                        'old' => ['companies' => $oldCompanies],
                        'attributes' => ['companies' => $newCompanies],
                    ]);
                }
            });
        } catch (Throwable $e) {
            // Never leave the just-written files behind when the save fails.
            foreach ($new as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        foreach ($new as $attribute => $path) {
            $icons->deleteReplaced($app, $old[$attribute], $path);
        }

        session()->flash('status', $this->app === null ? 'Aplicación creada. Ahora elige quién la ve.' : 'Cambios guardados.');

        return $this->redirectRoute('admin.apps.edit', $app->slug);
    }

    /**
     * Forgets what an external system reported (brief §5.2: an admin may
     * clear it by hand). Phase 8 keeps the report history.
     */
    public function clearExternalStatus(): void
    {
        if ($this->app === null) {
            return;
        }

        $this->authorize('update', $this->app);

        $app = $this->app->fresh();
        $old = $app->only(['external_status', 'external_status_message', 'external_status_at']);

        if ($old['external_status'] === null && $old['external_status_message'] === null && $old['external_status_at'] === null) {
            return;
        }

        $app->forceFill([
            'external_status' => null,
            'external_status_message' => null,
            'external_status_at' => null,
        ])->save();

        $app->audit('app.external_status_cleared', [
            'old' => [...$old, 'external_status_at' => $old['external_status_at']?->toIso8601String()],
        ]);

        $this->app = $app;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $web = $this->type === App::TYPE_WEB;

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => $this->app !== null ? [] : [
                'required', 'string', 'max:60', 'regex:'.self::SLUG_PATTERN,
                Rule::notIn(self::RESERVED_SLUGS), Rule::unique('apps', 'slug'),
            ],
            'description' => ['required', 'string', 'max:120'],
            'categoryId' => ['required', Rule::exists('app_categories', 'id')],
            'companyIds' => ['array'],
            'companyIds.*' => ['distinct', Rule::exists('companies', 'id')],
            'allCompanies' => ['boolean'],
            'type' => ['required', Rule::in(App::TYPES)],
            'url' => $web ? ['required', 'string', 'max:2048', new AllowedAppUrl] : [],
            'openIn' => $web ? ['required', Rule::in(App::OPEN_IN)] : [],
            'hosting' => $web ? ['required', Rule::in(self::WEB_HOSTINGS)] : [],
            'loginType' => $web ? ['required', Rule::in(self::WEB_LOGIN_TYPES)] : [],
            'localInstructions' => $web ? [] : ['required', 'string', 'max:2000'],
            'downloadUrl' => $web ? [] : ['nullable', 'string', 'max:2048', 'url:https', 'starts_with:https://'],
            'status' => ['required', Rule::in(App::STATUSES)],
            'maintenanceMessage' => $this->status === App::STATUS_MAINTENANCE ? ['required', 'string', 'max:200'] : [],
            'maintenanceUntil' => $this->status === App::STATUS_MAINTENANCE ? ['nullable', 'date_format:'.self::DATETIME_FORMAT] : [],
            'accentColor' => ['required', 'regex:'.self::HEX_PATTERN],
            'iconLight' => ['nullable', 'file', 'max:'.AppIconStore::MAX_KILOBYTES, 'extensions:'.implode(',', AppIconStore::EXTENSIONS), $this->isIcon(...)],
            'iconDark' => ['nullable', 'file', 'max:'.AppIconStore::MAX_KILOBYTES, 'extensions:'.implode(',', AppIconStore::EXTENSIONS), $this->isIcon(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'name.max' => 'Usa como máximo 120 caracteres.',
            'slug.required' => 'Escribe el identificador.',
            'slug.max' => 'Usa como máximo 60 caracteres.',
            'slug.regex' => 'Usa minúsculas, números y guiones simples, por ejemplo cuentas-por-pagar.',
            'slug.not_in' => 'Ese identificador está reservado. Elige otro.',
            'slug.unique' => 'Ya hay una aplicación con ese identificador.',
            'description.max' => 'Usa como máximo 120 caracteres.',
            'categoryId.required' => 'Elige una categoría.',
            'categoryId.exists' => 'Elige una categoría de la lista.',
            'companyIds.*.exists' => 'Elige empresas de la lista.',
            'url.required' => 'Escribe la dirección de la aplicación.',
            'localInstructions.required' => 'Escribe cómo abrir la aplicación en el equipo.',
            'localInstructions.max' => 'Usa como máximo 2000 caracteres.',
            'downloadUrl.url' => 'Escribe una dirección completa que empiece por https://.',
            'downloadUrl.starts_with' => 'Escribe una dirección completa que empiece por https://.',
            'maintenanceMessage.required' => 'Escribe el mensaje que verán las personas.',
            'maintenanceMessage.max' => 'Usa como máximo 200 caracteres.',
            'maintenanceUntil.date_format' => 'Elige una fecha y hora válidas.',
            'accentColor.regex' => 'Usa el formato #RRGGBB, por ejemplo #B45309.',
            'iconLight.max' => 'El archivo pesa más de 512 KB.',
            'iconDark.max' => 'El archivo pesa más de 512 KB.',
            'iconLight.extensions' => 'Sube un archivo SVG, PNG o WebP.',
            'iconDark.extensions' => 'Sube un archivo SVG, PNG o WebP.',
            'file' => 'No se pudo leer el archivo.',
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.app-editor', [
            'categories' => AppCategory::query()->ordered()->get(['id', 'name']),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'short_name', 'is_active']),
            'accent' => preg_match(self::HEX_PATTERN, $this->accentColor) === 1 ? strtoupper($this->accentColor) : '#334155',
            'statusEvents' => $this->app === null ? collect() : $this->app->statusEvents()
                ->with('integration:id,name')
                ->latest('id')
                ->limit(self::STATUS_HISTORY)
                ->get(),
            'statusTtl' => app(PortalSettings::class)->apiStatusTtlHours(),
        ]);
    }

    /**
     * The validated form as app attributes. Fields of the other type are
     * cleared, and so is the maintenance notice outside maintenance.
     *
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        $web = $this->type === App::TYPE_WEB;
        $maintenance = $this->status === App::STATUS_MAINTENANCE;

        return [
            'name' => $this->name,
            'description' => trim($this->description),
            'app_category_id' => (int) $this->categoryId,
            'available_to_all_companies' => $this->allCompanies,
            'type' => $this->type,
            'url' => $web ? $this->url : null,
            'open_in' => $web ? $this->openIn : App::OPEN_SAME,
            'hosting' => $web ? $this->hosting : 'local',
            'login_type' => $web ? $this->loginType : 'local',
            'local_instructions' => $web ? null : trim($this->localInstructions),
            'download_url' => $web || $this->downloadUrl === '' ? null : $this->downloadUrl,
            'status' => $this->status,
            'maintenance_message' => $maintenance ? trim($this->maintenanceMessage) : null,
            'maintenance_until' => $maintenance && $this->maintenanceUntil !== '' ? $this->maintenanceUntil : null,
            'accent_color' => strtoupper($this->accentColor),
        ];
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<string>
     */
    private function companyNames(array $ids): array
    {
        /** @var Collection<int, Company> $companies */
        $companies = Company::query()->whereKey($ids)->get(['id', 'name', 'short_name']);

        return $companies->map(fn (Company $company) => $company->short_name ?: $company->name)->sort()->values()->all();
    }

    private function isIcon(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof TemporaryUploadedFile && ($problem = app(AppIconStore::class)->problem($value)) !== null) {
            $fail($problem);
        }
    }
}
