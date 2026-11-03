<?php

namespace App\Livewire\Admin;

use App\Auth\RecentMicrosoftLogin;
use App\Models\App;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use App\Policies\AdminSections;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Integraciones (docs/diseno/AdminIntegrations.dc.html, AdminTokenNew.dc.html):
 * the external systems of the API and their tokens. Super admins only
 * (AdminSections::INTEGRATIONS); every action is re-authorized.
 *
 * A new or rotated token is shown once, in the «Token creado» dialog, and
 * only lives in this component until the dialog is closed: it is never
 * stored in plaintext nor written to the activity log.
 *
 * Creating or rotating a token hands out a new credential, so, like creating
 * a company, it needs a Microsoft sign-in from the last 10 minutes
 * (App\Auth\RecentMicrosoftLogin), checked again on every call. The create
 * form only opens once that holds, so nothing typed is lost on the way to
 * Microsoft. Granting `work:write` and changing the catalog app or base URL
 * of a system decide where the links of every person's home lead and where
 * «Completar» calls, so they need it too. Revoking, taking abilities away,
 * the other abilities and IPs stay one click (revoking a leaked token must
 * never wait).
 *
 * `work:write` (tasks and approvals, docs/contrato-tareas-aprobaciones.md
 * §2.2) also needs the catalog app whose host the links must use and the
 * base URL of the app: both are asked when creating, and must be saved
 * before granting it on an existing system.
 *
 * `kpis:write` (management panel, odd/tasks/panel-gerencial.md) works the
 * same way with the one company whose figures the system publishes: asked
 * when creating, saved before granting it, never taken away while granted,
 * and granting it or changing the company needs a recent Microsoft sign-in.
 *
 * A `work:write` system also has the signing secret of «Completar»
 * (`ryrsig_…`, contract §6.2): generated or rotated here behind a recent
 * Microsoft sign-in, shown once in the «Secreto de firma» dialog and only
 * kept in this component until it is closed, like a token.
 */
class IntegrationManager extends Component
{
    use AuthorizesRequests;

    public const LATEST_CHANGES = 5;

    /** Emergency accounts have no Microsoft identity to confirm with. */
    public const EMERGENCY_MESSAGE = 'Crear o rotar tokens o secretos de firma, dar work:write o kpis:write, o cambiar la app de tareas o la empresa de las cifras requiere iniciar sesión con Microsoft.';

    /** The system shown in the detail panel (`?sistema=` in the address). */
    #[Url(as: 'sistema', history: true)]
    public ?int $selectedId = null;

    #[Locked]
    public bool $creating = false;

    public string $name = '';

    /** @var list<string> */
    public array $abilities = [Integration::CATALOG_READ];

    /** Optional allowed IPs of a new token, separated by commas or spaces. */
    public string $ips = '';

    public string $newIp = '';

    /** Catalog app and base URL of a new `work:write` token. */
    public int|string|null $appId = null;

    public string $appBaseUrl = '';

    /** Catalog app and base URL being edited in the detail panel. */
    public int|string|null $workAppId = null;

    public string $workBaseUrl = '';

    /** Company of a new `kpis:write` token. */
    public int|string|null $companyId = null;

    /** Company of the management figures being edited in the detail panel. */
    public int|string|null $kpiCompanyId = null;

    /** Plaintext of the token just created or rotated; null once dismissed. */
    #[Locked]
    public ?string $plainToken = null;

    #[Locked]
    public bool $rotated = false;

    /** Plaintext of the signing secret just generated; null once dismissed. */
    #[Locked]
    public ?string $plainSecret = null;

    #[Locked]
    public bool $secretRotated = false;

    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize(AdminSections::INTEGRATIONS);

        $selected = $this->selectedId === null ? null : Integration::query()->find($this->selectedId);
        $this->selectedId = $selected?->id;
        $this->workAppId = $selected?->app_id;
        $this->workBaseUrl = (string) $selected?->app_base_url;
        $this->kpiCompanyId = $selected?->company_id;
    }

    public function select(int $id): void
    {
        $this->authorize(AdminSections::INTEGRATIONS);

        $integration = Integration::query()->findOrFail($id);
        $this->selectedId = $integration->id;
        $this->workAppId = $integration->app_id;
        $this->workBaseUrl = (string) $integration->app_base_url;
        $this->kpiCompanyId = $integration->company_id;
        $this->creating = false;
        $this->newIp = '';
        $this->notice = '';
        $this->resetErrorBag();
    }

    public function startCreating(RecentMicrosoftLogin $recent): void
    {
        if (! $this->hasRecentLogin($recent)) {
            return;
        }

        $this->creating = true;
        $this->name = '';
        $this->abilities = [Integration::CATALOG_READ];
        $this->ips = '';
        $this->appId = null;
        $this->appBaseUrl = '';
        $this->companyId = null;
        $this->notice = '';
        $this->resetErrorBag();
    }

    public function cancelCreating(): void
    {
        $this->creating = false;
        $this->resetErrorBag();
    }

    public function create(RecentMicrosoftLogin $recent): void
    {
        if (! $this->hasRecentLogin($recent)) {
            return;
        }

        $this->name = trim($this->name);
        $this->appBaseUrl = self::normalizeBaseUrl($this->appBaseUrl);
        $ips = [];
        $work = in_array(Integration::WORK_WRITE, $this->abilities, true);
        $kpis = in_array(Integration::KPIS_WRITE, $this->abilities, true);

        $this->withValidator(function ($validator) use (&$ips, $work, $kpis) {
            $validator->after(function ($validator) use (&$ips, $work, $kpis) {
                [$ips, $error] = self::parseIps($this->ips);

                if ($error !== null) {
                    $validator->errors()->add('ips', $error);
                }

                if ($work) {
                    foreach (self::workAppErrors(self::idOf($this->appId), $this->appBaseUrl, true) as $field => $message) {
                        $validator->errors()->add($field === 'app' ? 'appId' : 'appBaseUrl', $message);
                    }
                }

                $companyError = $kpis ? self::kpiCompanyError(self::idOf($this->companyId), true) : null;

                if ($companyError !== null) {
                    $validator->errors()->add('companyId', $companyError);
                }
            });
        })->validate([
            'name' => ['required', 'string', 'max:'.Integration::MAX_NAME],
            'abilities' => ['required', 'array'],
            'abilities.*' => ['string', Rule::in(array_keys(Integration::ABILITIES))],
        ], [
            'name.required' => 'Escribe el nombre del sistema.',
            'name.max' => 'El nombre admite hasta :max caracteres.',
            'abilities.required' => 'Elige al menos un permiso.',
            'abilities.*.in' => 'Ese permiso no existe.',
        ]);

        $integration = Integration::create([
            'name' => $this->name,
            'abilities' => $this->abilities,
            'allowed_ips' => $ips,
            'app_id' => $work ? self::idOf($this->appId) : null,
            'app_base_url' => $work ? $this->appBaseUrl : null,
            'company_id' => $kpis ? self::idOf($this->companyId) : null,
            'created_by' => Auth::id(),
        ]);
        $this->plainToken = $integration->issueToken();
        $this->rotated = false;

        $integration->audit('integration.created', [
            'attributes' => [
                'name' => $integration->name,
                'abilities' => $integration->abilities,
                'allowed_ips' => $integration->allowed_ips,
                ...($work ? self::workAppAudit($integration) : []),
                ...($kpis ? self::kpiCompanyAudit($integration) : []),
            ],
        ]);

        $this->creating = false;
        $this->selectedId = $integration->id;
        $this->workAppId = $integration->app_id;
        $this->workBaseUrl = (string) $integration->app_base_url;
        $this->kpiCompanyId = $integration->company_id;
    }

    public function dismissToken(): void
    {
        $this->plainToken = null;
        $this->rotated = false;
    }

    public function rotate(RecentMicrosoftLogin $recent): void
    {
        if (! $this->hasRecentLogin($recent)) {
            return;
        }

        $integration = $this->liveSelection();

        if ($integration === null) {
            return;
        }

        $this->plainToken = $integration->rotate();
        $this->rotated = true;
        $integration->audit('integration.rotated');
    }

    /**
     * Generates the signing secret of «Completar» of the selected
     * `work:write` system, or replaces it: the portal signs with the new one
     * at once.
     */
    public function generateSigningSecret(RecentMicrosoftLogin $recent): void
    {
        $integration = $this->liveSelection();
        $this->resetErrorBag();

        if ($integration === null) {
            return;
        }

        if (! in_array(Integration::WORK_WRITE, $integration->abilities, true)) {
            $this->addError('signingSecret', 'El secreto de firma solo sirve con work:write.');

            return;
        }

        if (! $this->hasRecentLogin($recent)) {
            return;
        }

        $rotated = $integration->signing_secret_hint !== null;
        $this->plainSecret = $integration->issueSigningSecret();
        $this->secretRotated = $rotated;
        $integration->audit($rotated ? 'integration.signing_secret_rotated' : 'integration.signing_secret_created');
    }

    public function dismissSecret(): void
    {
        $this->plainSecret = null;
        $this->secretRotated = false;
    }

    public function revoke(): void
    {
        $integration = $this->liveSelection();

        if ($integration === null) {
            return;
        }

        $integration->revoke();
        $integration->audit('integration.revoked');
        $this->notice = "Se revocó el token de {$integration->name}. Ya no funciona.";
    }

    public function toggleAbility(string $ability, RecentMicrosoftLogin $recent): void
    {
        $integration = $this->liveSelection();
        $this->resetErrorBag();

        if ($integration === null || ! array_key_exists($ability, Integration::ABILITIES)) {
            return;
        }

        $old = $integration->abilities;
        $new = in_array($ability, $old, true)
            ? array_values(array_diff($old, [$ability]))
            : [...$old, $ability];

        if (Integration::knownAbilities($new) === []) {
            $this->addError('abilities', 'Debe quedar al menos un permiso.');

            return;
        }

        $grantsWork = in_array(Integration::WORK_WRITE, $new, true) && ! in_array(Integration::WORK_WRITE, $old, true);

        if ($grantsWork && self::workAppErrors($integration->app_id, (string) $integration->app_base_url, true) !== []) {
            $this->addError('abilities', 'Antes de dar work:write, guarda la app del catálogo y la URL base.');

            return;
        }

        $grantsKpis = in_array(Integration::KPIS_WRITE, $new, true) && ! in_array(Integration::KPIS_WRITE, $old, true);

        if ($grantsKpis && self::kpiCompanyError($integration->company_id, true) !== null) {
            $this->addError('abilities', 'Antes de dar kpis:write, guarda la empresa de las cifras.');

            return;
        }

        if (($grantsWork || $grantsKpis) && ! $this->hasRecentLogin($recent)) {
            return;
        }

        if ($integration->changeAbilities($new)) {
            $integration->audit('integration.abilities_changed', [
                'old' => ['abilities' => $old],
                'attributes' => ['abilities' => $integration->abilities],
            ]);
            $this->notice = 'Permisos actualizados.';
        }
    }

    /**
     * Saves the catalog app and base URL of the selected system. While it
     * has `work:write`, both are required. A change needs a recent Microsoft
     * sign-in; saving the same values does nothing.
     */
    public function saveWorkApp(RecentMicrosoftLogin $recent): void
    {
        $integration = $this->liveSelection();
        $this->resetErrorBag();

        if ($integration === null) {
            return;
        }

        $this->workBaseUrl = self::normalizeBaseUrl($this->workBaseUrl);
        $required = in_array(Integration::WORK_WRITE, $integration->abilities, true);
        $errors = self::workAppErrors(self::idOf($this->workAppId), $this->workBaseUrl, $required);

        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError($field === 'app' ? 'workAppId' : 'workBaseUrl', $message);
            }

            return;
        }

        $old = self::workAppAudit($integration);
        $integration->forceFill([
            'app_id' => self::idOf($this->workAppId),
            'app_base_url' => $this->workBaseUrl === '' ? null : $this->workBaseUrl,
        ]);

        if ($integration->isDirty(['app_id', 'app_base_url']) && ! $this->hasRecentLogin($recent)) {
            return;
        }

        $integration->save();
        $new = self::workAppAudit($integration);

        if ($old !== $new) {
            $integration->audit('integration.work_app_changed', ['old' => $old, 'attributes' => $new]);
        }

        $this->notice = 'App de tareas actualizada.';
    }

    /**
     * Saves the company whose management figures the selected system
     * publishes. While it has `kpis:write`, one is required. A change needs
     * a recent Microsoft sign-in; saving the same company does nothing.
     */
    public function saveKpiCompany(RecentMicrosoftLogin $recent): void
    {
        $integration = $this->liveSelection();
        $this->resetErrorBag();

        if ($integration === null) {
            return;
        }

        $companyId = self::idOf($this->kpiCompanyId);
        $error = self::kpiCompanyError($companyId, in_array(Integration::KPIS_WRITE, $integration->abilities, true));

        if ($error !== null) {
            $this->addError('kpiCompanyId', $error);

            return;
        }

        $old = self::kpiCompanyAudit($integration);
        $integration->forceFill(['company_id' => $companyId]);

        if ($integration->isDirty('company_id') && ! $this->hasRecentLogin($recent)) {
            return;
        }

        $integration->save();
        $new = self::kpiCompanyAudit($integration);

        if ($old !== $new) {
            $integration->audit('integration.kpi_company_changed', ['old' => $old, 'attributes' => $new]);
        }

        $this->notice = 'Empresa de las cifras actualizada.';
    }

    public function addIp(): void
    {
        $integration = $this->liveSelection();
        $this->resetErrorBag();

        if ($integration === null) {
            return;
        }

        $ip = strtolower(trim($this->newIp));
        $current = $integration->allowed_ips ?? [];

        $error = match (true) {
            ! Integration::isValidIpEntry($ip) => 'Escribe una IP (como 10.0.0.5) o un rango (como 192.168.1.0/24).',
            in_array($ip, $current, true) => 'Esa IP ya está en la lista.',
            count($current) >= Integration::MAX_IPS => 'La lista admite hasta '.Integration::MAX_IPS.' IP.',
            default => null,
        };

        if ($error !== null) {
            $this->addError('newIp', $error);

            return;
        }

        $this->saveIps($integration, [...$current, $ip]);
        $this->newIp = '';
    }

    public function removeIp(string $ip): void
    {
        $integration = $this->liveSelection();

        if ($integration === null || ! in_array($ip, $integration->allowed_ips ?? [], true)) {
            return;
        }

        $this->saveIps($integration, array_values(array_diff($integration->allowed_ips, [$ip])));
    }

    public function render(): View
    {
        $integrations = Integration::query()
            ->orderByRaw('revoked_at is not null')
            ->orderBy('name')
            ->get();

        $selected = $this->selectedId === null ? null : $integrations->firstWhere('id', $this->selectedId);
        $selected?->load(['creator:id,name']);
        $integrations->load('company:id,name');

        return view('livewire.admin.integration-manager', [
            'integrations' => $integrations,
            'selected' => $selected,
            'latestChanges' => $selected === null ? new Collection : $selected->statusEvents()
                ->with('app:id,slug')
                ->latest('id')
                ->limit(self::LATEST_CHANGES)
                ->get(),
            'tokenOwner' => $this->plainToken === null ? null : $selected,
            'catalogApps' => self::catalogApps(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Authorizes the action and tells whether the user confirmed their
     * Microsoft sign-in within RecentMicrosoftLogin::TTL_SECONDS; when not,
     * redirects to that confirmation (back to this page, with the selected
     * system). Emergency accounts can never confirm.
     */
    private function hasRecentLogin(RecentMicrosoftLogin $recent): bool
    {
        $this->authorize(AdminSections::INTEGRATIONS);

        $user = Auth::user();

        if (! $user instanceof User || $user->is_emergency || $user->azure_oid === null) {
            abort(403, self::EMERGENCY_MESSAGE);
        }

        if ($recent->isFresh($user)) {
            return true;
        }

        $this->redirectRoute('auth.microsoft.confirm', [
            'return' => route('admin.integrations.index', $this->selectedId === null ? [] : ['sistema' => $this->selectedId], false),
        ]);

        return false;
    }

    /**
     * The selected integration when it may still be changed (not revoked).
     */
    private function liveSelection(): ?Integration
    {
        $this->authorize(AdminSections::INTEGRATIONS);
        $this->notice = '';

        $integration = $this->selectedId === null ? null : Integration::query()->find($this->selectedId);

        return $integration === null || $integration->isRevoked() ? null : $integration;
    }

    /**
     * @param  list<string>  $ips
     */
    private function saveIps(Integration $integration, array $ips): void
    {
        $old = $integration->allowed_ips ?? [];
        $integration->allowed_ips = $ips;
        $integration->save();

        $integration->audit('integration.ips_changed', [
            'old' => ['allowed_ips' => $old],
            'attributes' => ['allowed_ips' => $ips],
        ]);
        $this->notice = 'IP permitidas actualizadas.';
    }

    /**
     * Web apps of the catalog with a URL: their host is the one the links
     * of the work items must use.
     *
     * @return Collection<int, App>
     */
    private static function catalogApps(): Collection
    {
        return App::query()
            ->where('type', App::TYPE_WEB)
            ->whereNotNull('url')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'url']);
    }

    /**
     * Why the catalog app or the base URL cannot be saved, keyed `app` and
     * `url`. Empty values are only an error when required.
     *
     * @return array<string, string>
     */
    private static function workAppErrors(?int $appId, string $baseUrl, bool $required): array
    {
        $errors = [];

        if ($appId === null) {
            if ($required) {
                $errors['app'] = 'Elige la app del catálogo.';
            }
        } elseif (! self::catalogApps()->contains('id', $appId)) {
            $errors['app'] = 'Esa app no está en el catálogo o no tiene dirección.';
        }

        if ($baseUrl === '') {
            if ($required) {
                $errors['url'] = 'Escribe la URL base de la app.';
            }
        } elseif (! Integration::isValidAppBaseUrl($baseUrl)) {
            $errors['url'] = 'Escribe una dirección https, sin usuario, sin ? ni #.';
        } elseif ($appId !== null && ! isset($errors['app'])
            && ! Integration::isOnHostOf($baseUrl, self::catalogApps()->firstWhere('id', $appId)?->url)) {
            // «Completar» only calls that host (Integration::completionUrl()).
            $errors['url'] = 'La URL base debe estar en el mismo host (y puerto) que la app del catálogo.';
        }

        return $errors;
    }

    /**
     * The catalog app (by its slug, readable in Auditoría) and base URL.
     *
     * @return array{app: ?string, app_base_url: ?string}
     */
    private static function workAppAudit(Integration $integration): array
    {
        return [
            'app' => $integration->app_id === null ? null : App::query()->whereKey($integration->app_id)->value('slug'),
            'app_base_url' => $integration->app_base_url,
        ];
    }

    /**
     * Why the company of the management figures cannot be saved, if any.
     * No company is only an error when required.
     */
    private static function kpiCompanyError(?int $companyId, bool $required): ?string
    {
        if ($companyId === null) {
            return $required ? 'Elige la empresa de las cifras.' : null;
        }

        return Company::query()->whereKey($companyId)->exists() ? null : 'Esa empresa no existe.';
    }

    /**
     * The company of the management figures, by its slug (readable in
     * Auditoría).
     *
     * @return array{company: ?string}
     */
    private static function kpiCompanyAudit(Integration $integration): array
    {
        return [
            'company' => $integration->company_id === null ? null : Company::query()->whereKey($integration->company_id)->value('slug'),
        ];
    }

    /**
     * The app ID of a select («» means none).
     */
    private static function idOf(int|string|null $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * Trimmed, without the trailing slash the portal adds itself.
     */
    private static function normalizeBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * The IPs of the create form (separated by commas, semicolons or
     * spaces) and the reason they are not valid, if any.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private static function parseIps(string $input): array
    {
        $ips = array_values(array_unique(preg_split('/[\s,;]+/', strtolower(trim($input)), -1, PREG_SPLIT_NO_EMPTY) ?: []));

        foreach ($ips as $ip) {
            if (! Integration::isValidIpEntry($ip)) {
                return [[], "«{$ip}» no es una IP ni un rango válido."];
            }
        }

        if (count($ips) > Integration::MAX_IPS) {
            return [[], 'La lista admite hasta '.Integration::MAX_IPS.' IP.'];
        }

        return [$ips, null];
    }
}
