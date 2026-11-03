<?php

namespace App\Livewire\Admin;

use App\Models\Company;
use App\Models\ItService;
use App\Models\User;
use App\Policies\Rules\CompanyAddressedRules;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Administración › Estado de TI: the services of one company that IT
 * reports by hand (the apps report their own status in Aplicaciones).
 * Admins pick any company; announcements editors get only their own
 * (another company in `?empresa=` or sent by the browser is a 403).
 * Every action and every render re-checks App\Policies\ItServicePolicy,
 * for the selected company too, and every record is looked up inside
 * that company, so an id from another company is a 404.
 */
class ItServiceManager extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'empresa')]
    public ?int $companyId = null;

    #[Locked]
    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $status = ItService::STATUS_OPERATIONAL;

    public string $message = '';

    /** Confirmation of the last change, read by screen readers. */
    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ItService::class);

        $this->companyId = $this->resolveCompanyId($this->companyId);
    }

    public function updatedCompanyId(): void
    {
        $this->authorize('viewAny', ItService::class);

        $this->companyId = $this->resolveCompanyId($this->companyId);
        $this->cancelService();
        $this->notice = '';
    }

    public function newService(): void
    {
        $this->authorize('create', ItService::class);

        $this->cancelService();
        $this->showForm = true;
    }

    public function editService(int $id): void
    {
        $service = $this->service($id);
        $this->authorize('update', $service);

        $this->cancelService();
        $this->showForm = true;
        $this->editingId = $service->id;
        $this->name = $service->name;
        $this->status = $service->status;
        $this->message = (string) $service->message;
    }

    public function cancelService(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'status', 'message');
        $this->resetValidation();
    }

    public function saveService(): void
    {
        $service = $this->editingId === null ? null : $this->service($this->editingId);
        $this->authorize($service === null ? 'create' : 'update', $service ?? ItService::class);

        $company = $this->company();
        $this->name = self::squish($this->name);
        $this->message = self::squish($this->message);
        $data = $this->validate($this->rules(), $this->messages());

        $this->checkNameIsFree($company, $data['name'], $service?->id);

        $attributes = [
            'name' => $data['name'],
            'status' => $data['status'],
            'message' => $data['message'] === '' ? null : $data['message'],
        ];

        try {
            if ($service === null) {
                ItService::create($attributes + [
                    'company_id' => $company->id,
                    'position' => (int) ItService::query()->where('company_id', $company->id)->max('position') + 1,
                ]);
            } else {
                $service->update($attributes);
            }
        } catch (UniqueConstraintViolationException) {
            throw self::nameTaken();
        }

        $created = $service === null;
        $this->cancelService();
        $this->notice = $created
            ? "Se agregó «{$attributes['name']}» al final de los servicios."
            : "Se guardaron los cambios de «{$attributes['name']}».";
    }

    public function moveService(int $id, int $step): void
    {
        $service = $this->service($id);
        $this->authorize('update', $service);

        $order = ItService::query()->where('company_id', $service->company_id)->ordered()->pluck('id')->all();
        $from = array_search($service->id, $order, true);
        $to = $from + ($step < 0 ? -1 : 1);

        if (! isset($order[$to])) {
            return;
        }

        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        // Numbers the rows 1..n without one audit entry per row: a single
        // «moved» entry is written below.
        DB::transaction(function () use ($order) {
            foreach ($order as $position => $serviceId) {
                ItService::query()->whereKey($serviceId)->update(['position' => $position + 1]);
            }
        });

        $service->audit('it_service.moved', [
            'old' => ['position' => $from + 1],
            'attributes' => ['position' => $to + 1],
        ]);

        $this->notice = "«{$service->name}» quedó en la posición ".($to + 1).'.';
    }

    public function deleteService(int $id): void
    {
        $service = $this->service($id);
        $this->authorize('delete', $service);

        $service->delete();

        if ($this->editingId === $id) {
            $this->cancelService();
        }

        $this->notice = "Se borró «{$service->name}».";
    }

    public function render(): View
    {
        $this->authorize('viewAny', ItService::class);

        // Announcements editors are offered their own company only.
        $companies = Company::query()
            ->when(! CompanyAddressedRules::manages($this->user()), fn ($query) => $query->whereKey($this->user()->company_id))
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'is_active']);
        $company = $companies->firstWhere('id', $this->companyId);

        if ($company !== null) {
            $this->authorize('manageCompany', [ItService::class, $company]);
        }

        $services = $company === null
            ? new Collection
            : ItService::query()->where('company_id', $company->id)->ordered()->get();

        return view('livewire.admin.it-service-manager', [
            'companies' => $companies,
            'company' => $company,
            'services' => $services,
            'statuses' => ItService::statusOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ItService::NAME_MAX],
            'status' => ['required', 'string', Rule::in(ItService::STATUSES)],
            'message' => ['nullable', 'string', 'max:'.ItService::MESSAGE_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del servicio.',
            'name.max' => 'Usa como máximo '.ItService::NAME_MAX.' caracteres.',
            'status.required' => 'Elige el estado del servicio.',
            'status.in' => 'Elige uno de los estados de la lista.',
            'message.max' => 'Usa como máximo '.ItService::MESSAGE_MAX.' caracteres.',
        ];
    }

    /**
     * The selected company, or a 404 when it does not exist; a 403 when
     * the user may not manage it.
     */
    private function company(): Company
    {
        $company = $this->companyId === null ? null : Company::find($this->companyId);

        abort_if($company === null, 404);

        $this->authorize('manageCompany', [ItService::class, $company]);

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

    private function service(int $id): ItService
    {
        $service = ItService::query()->where('company_id', $this->company()->id)->find($id);

        abort_if($service === null, 404);

        return $service;
    }

    /**
     * Compares names the way MariaDB's utf8mb4_unicode_ci does (without
     * case or accents), so SQLite and production refuse the same names.
     */
    private function checkNameIsFree(Company $company, string $name, ?int $except): void
    {
        $taken = ItService::query()
            ->where('company_id', $company->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->pluck('name')
            ->contains(fn (string $existing) => self::fold($existing) === self::fold($name));

        if ($taken) {
            throw self::nameTaken();
        }
    }

    private static function fold(string $name): string
    {
        return mb_strtolower(Str::ascii($name));
    }

    private static function squish(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Two admins can save at once, so the unique index has the last word:
     * it ends here, as a form error.
     */
    private static function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'Esta empresa ya tiene un servicio con ese nombre.']);
    }
}
