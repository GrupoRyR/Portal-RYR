<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Models\NewsSource;
use App\Models\User;
use App\News\FeedFailure;
use App\News\FeedUrlPolicy;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin › Noticias: the RSS/Atom sources of each company and tab of the
 * home «Noticias». Super admins only (App\Policies\NewsSourcePolicy, like
 * Integraciones: the sources make the server request third-party URLs),
 * checked on mount, on every action and on every render.
 *
 * Saving never requests the URL: `portal:fetch-news` reads the sources
 * every hour with the SSRF rules of App\News\FeedFetcher, and this page
 * only checks the shape of the URL (App\News\FeedUrlPolicy). Every change
 * is audited by App\Models\NewsSource (log `news`).
 */
class NewsManager extends Component
{
    use AuthorizesRequests;

    /** Spanish text of each App\News\FeedFailure code; `http_*` is built. */
    public const ERROR_LABELS = [
        FeedFailure::INVALID_URL => 'La dirección no es válida',
        FeedFailure::DNS_FAILED => 'El nombre del sitio no existe',
        FeedFailure::BLOCKED_ADDRESS => 'La dirección apunta a una red interna',
        FeedFailure::TOO_MANY_REDIRECTS => 'Demasiadas redirecciones',
        FeedFailure::CONNECTION_FAILED => 'No se pudo conectar',
        FeedFailure::TIMEOUT => 'No respondió en 10 segundos',
        FeedFailure::TOO_LARGE => 'La respuesta pasa de 1 MB',
        FeedFailure::DOCTYPE => 'El documento no es seguro',
        FeedFailure::INVALID_XML => 'No es un XML válido',
        FeedFailure::UNSUPPORTED_FORMAT => 'No es RSS ni Atom',
        FeedFailure::UNEXPECTED => 'Error inesperado',
    ];

    /** The company whose sources are listed (`?empresa=`). */
    #[Url(as: 'empresa', history: true)]
    public ?int $companyId = null;

    /** The tab whose sources are listed (`?pestana=`). */
    #[Url(as: 'pestana', history: true)]
    public string $tab = NewsSource::TAB_COLOMBIA;

    /** The source being edited; null while adding. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    /** Tab of the source being edited (a new one goes to the listed tab). */
    public string $formTab = NewsSource::TAB_COLOMBIA;

    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize('viewAny', NewsSource::class);
        $this->normalizeSelection();
    }

    public function updatedCompanyId(): void
    {
        $this->leaveSelection();
    }

    public function updatedTab(): void
    {
        $this->leaveSelection();
    }

    public function create(): void
    {
        $this->authorize('create', NewsSource::class);
        $this->normalizeSelection();
        $this->notice = '';
        $this->formTab = $this->tab;

        $this->validateForm($this->tab, null);

        $source = NewsSource::create([
            'company_id' => $this->companyId,
            'tab' => $this->tab,
            'name' => $this->name,
            'url' => $this->url,
            'active' => true,
        ]);

        $this->resetForm();
        $this->notice = "Se agregó la fuente «{$source->name}».";
    }

    public function startEditing(int $id): void
    {
        $this->authorize('viewAny', NewsSource::class);

        $source = NewsSource::query()->findOrFail($id);
        $this->authorize('update', $source);

        $this->editingId = $source->id;
        $this->name = $source->name;
        $this->url = $source->url;
        $this->formTab = $source->tab;
        $this->notice = '';
        $this->resetErrorBag();
    }

    public function cancelEditing(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('viewAny', NewsSource::class);

        $source = $this->editingId === null ? null : NewsSource::query()->find($this->editingId);

        if ($source === null) {
            $this->resetForm();

            return;
        }

        $this->authorize('update', $source);
        $this->notice = '';

        $this->validateForm($this->formTab, $source);

        $source->fill(['name' => $this->name, 'url' => $this->url, 'tab' => $this->formTab]);

        // A new address starts over: the error of the old one no longer applies.
        if ($source->isDirty('url')) {
            $source->forceFill(['last_error_code' => null, 'last_fetched_at' => null]);
        }

        $source->save();

        $this->tab = $source->tab;
        $this->resetForm();
        $this->notice = "Se guardó la fuente «{$source->name}».";
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('viewAny', NewsSource::class);

        $source = NewsSource::query()->findOrFail($id);
        $this->authorize('update', $source);

        $source->update(['active' => ! $source->active]);

        $this->notice = $source->active
            ? "Se activó la fuente «{$source->name}»."
            : "Se desactivó la fuente «{$source->name}».";
    }

    public function delete(int $id): void
    {
        $this->authorize('viewAny', NewsSource::class);

        $source = NewsSource::query()->findOrFail($id);
        $this->authorize('delete', $source);

        $source->delete();

        if ($this->editingId === $source->id) {
            $this->resetForm();
        }

        $this->notice = "Se borró la fuente «{$source->name}».";
    }

    public function render(): View
    {
        $this->authorize('viewAny', NewsSource::class);
        $companies = self::companies();
        $this->normalizeSelection($companies);

        $sources = NewsSource::query()
            ->where('company_id', $this->companyId)
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $counts = array_map(fn () => 0, NewsSource::TABS);
        foreach ($sources as $source) {
            $counts[$source->tab] = ($counts[$source->tab] ?? 0) + 1;
        }

        return view('livewire.admin.news-manager', [
            'companies' => $companies,
            'company' => $companies->firstWhere('id', $this->companyId),
            'sources' => $sources->where('tab', $this->tab)->values(),
            'counts' => $counts,
        ]);
    }

    /**
     * «El sitio respondió 404 (http_404)», «No se pudo conectar (connection_failed)»…
     */
    public static function errorLabel(string $code): string
    {
        $label = preg_match('/\Ahttp_(\d{3})\z/', $code, $match) === 1
            ? "El sitio respondió {$match[1]}"
            : (self::ERROR_LABELS[$code] ?? 'Error');

        return "{$label} ({$code})";
    }

    private function validateForm(string $tab, ?NewsSource $source): void
    {
        $this->name = trim($this->name);
        $this->url = trim($this->url);

        $this->validate([
            'name' => ['required', 'string', 'max:'.NewsSource::NAME_MAX],
            'url' => [
                'required',
                'string',
                'max:'.FeedUrlPolicy::MAX_URL,
                function (string $attribute, mixed $value, Closure $fail) {
                    if (FeedUrlPolicy::hostOf(is_string($value) ? $value : null) === null) {
                        $fail('Escribe una dirección https, sin usuario ni puerto, con un nombre de dominio (no una IP).');
                    }
                },
                Rule::unique('news_sources', 'url')
                    ->where('company_id', $source?->company_id ?? $this->companyId)
                    ->where('tab', $tab)
                    ->ignore($source?->id),
            ],
            'formTab' => ['required', Rule::in(array_keys(NewsSource::TABS))],
        ], [
            'name.required' => 'Escribe el nombre de la fuente.',
            'name.max' => 'El nombre admite hasta :max caracteres.',
            'url.required' => 'Escribe la dirección del RSS o Atom.',
            'url.max' => 'La dirección admite hasta :max caracteres.',
            'url.unique' => 'Esa fuente ya está en esta pestaña.',
            'formTab.in' => 'Esa pestaña no existe.',
        ]);
    }

    /**
     * Another company or tab: an edit in progress is dropped (it belongs to
     * the old list); what was typed to add a source stays.
     */
    private function leaveSelection(): void
    {
        $this->notice = '';

        if ($this->editingId !== null) {
            $this->resetForm();
        } else {
            $this->resetErrorBag();
        }
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->url = '';
        $this->formTab = $this->tab;
        $this->resetErrorBag();
    }

    /**
     * A known tab and an existing company: the user's own, else the first
     * by name.
     */
    private function normalizeSelection(?Collection $companies = null): void
    {
        if (! array_key_exists($this->tab, NewsSource::TABS)) {
            $this->tab = array_key_first(NewsSource::TABS);
        }

        $companies ??= self::companies();

        if ($companies->contains('id', $this->companyId)) {
            return;
        }

        $user = Auth::user();
        $own = $user instanceof User ? $user->company_id : null;

        $this->companyId = $companies->contains('id', $own) ? $own : $companies->first()?->id;
    }

    /**
     * @return Collection<int, Company>
     */
    private static function companies(): Collection
    {
        return Company::query()->orderBy('name')->get(['id', 'name', 'short_name']);
    }
}
