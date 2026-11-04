<?php

namespace App\Livewire\Admin;

use App\Markets\MarketBoard;
use App\Markets\MarketSeries;
use App\Markets\MarketUnit;
use App\Models\Company;
use App\Models\MarketInput;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Administración › Mercados (odd/tasks/mercados-o5.md M4), one company at
 * a time:
 *
 * - «Insumos que ve esta empresa»: the input series of the catalogue
 *   (App\Markets\MarketSeries) its home band shows, saved in
 *   `companies.market_codes` (unknown codes are dropped by the model);
 * - «Precios ingresados a mano»: prices typed by hand
 *   (App\Models\MarketInput), the newest LIST_LIMIT listed, with add, edit
 *   and delete.
 *
 * Admins pick any company; announcements editors get only their own
 * (another company in `?empresa=` or sent by the browser is a 403). Every
 * action and every render re-checks App\Policies\MarketInputPolicy, for
 * the selected company too, and every price is looked up inside that
 * company, so an id from another company is a 404. Every change goes to
 * Auditoría through the models.
 */
class MarketManager extends Component
{
    use AuthorizesRequests;

    /** Prices listed, newest first. */
    public const LIST_LIMIT = 50;

    /** A point that could be a decimal point is refused, not guessed. */
    private const COMMA_MESSAGE = 'Usa coma para los decimales, por ejemplo 4.200,50.';

    #[Url(as: 'empresa')]
    public ?int $companyId = null;

    /** @var list<string> The input codes checked. */
    public array $codes = [];

    #[Locked]
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $label = '';

    public string $unit = '';

    public string $value = '';

    /** `Y-m-d`, from a date field. */
    public string $validOn = '';

    public string $source = '';

    /** Confirmation of the last change, read by screen readers. */
    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize('viewAny', MarketInput::class);

        $this->companyId = $this->resolveCompanyId($this->companyId);
        $this->readCodes();
    }

    public function updatedCompanyId(): void
    {
        $this->authorize('viewAny', MarketInput::class);

        $this->companyId = $this->resolveCompanyId($this->companyId);
        $this->cancelInput();
        $this->readCodes();
        $this->notice = '';
    }

    public function saveCodes(): void
    {
        $company = $this->company();

        // The model keeps only known input codes, once each, in catalogue
        // order (App\Models\Company::setMarketCodesAttribute()).
        $company->update(['market_codes' => array_values(array_filter($this->codes, 'is_string'))]);

        $this->codes = $company->marketCodes();
        $this->notice = 'Se guardaron los insumos de '.self::companyName($company).'.';
    }

    public function newInput(): void
    {
        $this->authorize('create', MarketInput::class);

        $this->cancelInput();
        $this->showForm = true;
        $this->unit = MarketUnit::PesosPerKilo->value;
        $this->validOn = self::today();
    }

    public function editInput(int $id): void
    {
        $input = $this->input($id);
        $this->authorize('update', $input);

        $this->cancelInput();
        $this->showForm = true;
        $this->editingId = $input->id;
        $this->label = $input->label;
        $this->unit = $input->unit->value;
        $this->value = self::editableNumber($input->value);
        $this->validOn = $input->valid_on->toDateString();
        $this->source = (string) $input->source;
    }

    public function cancelInput(): void
    {
        $this->reset('showForm', 'editingId', 'label', 'unit', 'value', 'validOn', 'source');
        $this->resetValidation();
    }

    public function saveInput(): void
    {
        $input = $this->editingId === null ? null : $this->input($this->editingId);
        $this->authorize($input === null ? 'create' : 'update', $input ?? MarketInput::class);

        $company = $this->company();

        // Normalized copies are validated and saved; the fields keep what
        // was typed, so an error never rewrites the form.
        $value = self::decimal($this->value);

        if ($value === null) {
            throw ValidationException::withMessages(['value' => self::COMMA_MESSAGE]);
        }

        $data = Validator::make([
            'label' => self::squish($this->label),
            'unit' => $this->unit,
            'value' => $value,
            'validOn' => $this->validOn,
            'source' => self::squish($this->source),
        ], $this->rules(), $this->messages())->validate();

        $attributes = [
            'label' => $this->spellingInUse($company, $data['label'], $input?->id),
            'unit' => $data['unit'],
            'value' => $data['value'],
            'valid_on' => $data['validOn'],
            'source' => $data['source'] === '' ? null : $data['source'],
        ];

        if ($input === null) {
            MarketInput::create($attributes + ['company_id' => $company->id, 'created_by' => $this->user()->id]);
        } else {
            $input->update($attributes);
        }

        $this->cancelInput();
        $this->notice = $input === null
            ? "Se agregó el precio de «{$attributes['label']}»."
            : "Se guardaron los cambios del precio de «{$attributes['label']}».";
    }

    public function deleteInput(int $id): void
    {
        $input = $this->input($id);
        $this->authorize('delete', $input);

        $input->delete();

        if ($this->editingId === $id) {
            $this->cancelInput();
        }

        $this->notice = "Se borró el precio de «{$input->label}».";
    }

    public function render(): View
    {
        $this->authorize('viewAny', MarketInput::class);

        // Announcements editors are offered their own company only.
        $companies = Company::query()
            ->when(! CompanyAddressedRules::manages($this->user()), fn ($query) => $query->whereKey($this->user()->company_id))
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'is_active']);
        $company = $companies->firstWhere('id', $this->companyId);

        if ($company !== null) {
            $this->authorize('manageCompany', [MarketInput::class, $company]);
        }

        $inputs = $company === null
            ? new Collection
            : MarketInput::query()->where('company_id', $company->id)->newestFirst()->limit(self::LIST_LIMIT)->get();

        $series = collect(MarketSeries::all());

        return view('livewire.admin.market-manager', [
            'companies' => $companies,
            'company' => $company,
            'inputs' => $inputs,
            'total' => $inputs->count() < self::LIST_LIMIT ? $inputs->count() : MarketInput::query()->where('company_id', $company?->id)->count(),
            'inputSeries' => $series->where('scope', MarketSeries::SCOPE_INPUT)->values(),
            'globalSeries' => $series->where('scope', MarketSeries::SCOPE_ALL)->values(),
            'units' => MarketUnit::manualOptions(),
            'today' => self::today(),
            'firstDay' => self::firstDay()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:'.MarketInput::LABEL_MAX],
            'unit' => ['required', 'string', Rule::in(MarketUnit::manualValues())],
            'value' => ['required', 'numeric', 'gt:0', 'max:'.MarketInput::VALUE_MAX, 'decimal:0,4'],
            'validOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.self::today(), 'after_or_equal:'.self::firstDay()->toDateString()],
            'source' => ['nullable', 'string', 'max:'.MarketInput::SOURCE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'label.required' => 'Escribe el nombre del insumo.',
            'label.max' => 'Usa como máximo '.MarketInput::LABEL_MAX.' caracteres.',
            'unit.required' => 'Elige la unidad del precio.',
            'unit.in' => 'Elige una de las unidades de la lista.',
            'value.required' => 'Escribe el valor.',
            'value.numeric' => 'Escribe el valor como un número, por ejemplo 4200,50.',
            'value.gt' => 'El valor debe ser mayor que cero.',
            'value.max' => 'El valor es demasiado grande.',
            'value.decimal' => 'Usa como máximo cuatro decimales.',
            'validOn.required' => 'Escribe la fecha del precio.',
            'validOn.date_format' => 'Escribe una fecha válida.',
            'validOn.before_or_equal' => 'La fecha no puede ser futura.',
            'validOn.after_or_equal' => 'El inicio solo muestra precios de los últimos '.MarketBoard::WINDOW_DAYS.' días: usa una fecha desde el '
                .str_replace('.', '', self::firstDay()->locale('es')->isoFormat('D MMM YYYY')).'.',
            'source.max' => 'Usa como máximo '.MarketInput::SOURCE_MAX.' caracteres.',
        ];
    }

    private function readCodes(): void
    {
        $this->codes = $this->companyId === null ? [] : (Company::find($this->companyId)?->marketCodes() ?? []);
    }

    /**
     * The selected company, or a 404 when it does not exist; a 403 when
     * the user may not manage it.
     */
    private function company(): Company
    {
        $company = $this->companyId === null ? null : Company::find($this->companyId);

        abort_if($company === null, 404);

        $this->authorize('manageCompany', [MarketInput::class, $company]);

        return $company;
    }

    private function resolveCompanyId(?int $id): ?int
    {
        $user = $this->user();

        // Announcements editors only ever get their own company: another
        // one in the address (or sent by the browser) is refused, not swapped.
        if (! CompanyAddressedRules::manages($user)) {
            abort_if($user->company_id === null || ($id !== null && $id !== (int) $user->company_id), 403);

            return (int) $user->company_id;
        }

        if ($id !== null && Company::query()->whereKey($id)->exists()) {
            return $id;
        }

        return Company::query()->orderBy('name')->value('id');
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function input(int $id): MarketInput
    {
        $input = MarketInput::query()->where('company_id', $this->company()->id)->find($id);

        abort_if($input === null, 404);

        return $input;
    }

    /**
     * The spelling of the same name already used by this company («Soda
     * Cáustica» for «soda caustica»), so the band groups both under one
     * name in SQLite and in MariaDB alike; the typed one when it is new.
     */
    private function spellingInUse(Company $company, string $label, ?int $except): string
    {
        return MarketInput::query()
            ->where('company_id', $company->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->distinct()
            ->pluck('label')
            ->first(fn (string $existing) => MarketInput::fold($existing) === MarketInput::fold($label)) ?? $label;
    }

    /** Today in Bogotá, the markets' day (MarketSeries::TZ). */
    private static function today(): string
    {
        return CarbonImmutable::now(MarketSeries::TZ)->toDateString();
    }

    /**
     * The first day the band still reads (MarketBoard::WINDOW_DAYS before
     * today in Bogotá): an older price would be saved but never shown.
     */
    private static function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::now(MarketSeries::TZ)->startOfDay()->subDays(MarketBoard::WINDOW_DAYS);
    }

    /**
     * Reads a number the Colombian way: points group thousands, the comma
     * is the decimal mark. «4.200» → «4200», «4.200,50» → «4200.50»,
     * «4200,5» → «4200.5». Null for a point that could be a decimal point
     * («4.2», «1.35», «1,234.5»): it is refused rather than guessed.
     * Anything else (empty, words) is left for the rules to explain.
     */
    private static function decimal(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $value) === 1) {
            return str_replace(',', '.', str_replace('.', '', $value));
        }

        if (str_contains($value, '.')) {
            return null;
        }

        return str_replace(',', '.', $value);
    }

    /** «5100.0000» → «5100», «4200.5000» → «4200,5»: as a person would type it. */
    private static function editableNumber(string $value): string
    {
        return str_contains($value, '.') ? str_replace('.', ',', rtrim(rtrim($value, '0'), '.')) : $value;
    }

    private static function companyName(Company $company): string
    {
        return $company->short_name ?: $company->name;
    }

    private static function squish(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
