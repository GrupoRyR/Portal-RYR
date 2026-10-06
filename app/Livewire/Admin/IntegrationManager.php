<?php

namespace App\Livewire\Admin;

use App\Auth\RecentMicrosoftLogin;
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
 * Microsoft. Revoking, abilities and IPs only take access away or narrow
 * it, and stay one click (revoking a leaked token must never wait).
 */
class IntegrationManager extends Component
{
    use AuthorizesRequests;

    public const LATEST_CHANGES = 5;

    /** Emergency accounts have no Microsoft identity to confirm with. */
    public const EMERGENCY_MESSAGE = 'Crear o rotar tokens requiere iniciar sesión con Microsoft.';

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

    /** Plaintext of the token just created or rotated; null once dismissed. */
    #[Locked]
    public ?string $plainToken = null;

    #[Locked]
    public bool $rotated = false;

    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize(AdminSections::INTEGRATIONS);

        if ($this->selectedId !== null && ! Integration::query()->whereKey($this->selectedId)->exists()) {
            $this->selectedId = null;
        }
    }

    public function select(int $id): void
    {
        $this->authorize(AdminSections::INTEGRATIONS);

        $this->selectedId = Integration::query()->findOrFail($id)->id;
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
        $ips = [];

        $this->withValidator(function ($validator) use (&$ips) {
            $validator->after(function ($validator) use (&$ips) {
                [$ips, $error] = self::parseIps($this->ips);

                if ($error !== null) {
                    $validator->errors()->add('ips', $error);
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
            'created_by' => Auth::id(),
        ]);
        $this->plainToken = $integration->issueToken();
        $this->rotated = false;

        $integration->audit('integration.created', [
            'attributes' => [
                'name' => $integration->name,
                'abilities' => $integration->abilities,
                'allowed_ips' => $integration->allowed_ips,
            ],
        ]);

        $this->creating = false;
        $this->selectedId = $integration->id;
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

    public function toggleAbility(string $ability): void
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

        if ($integration->changeAbilities($new)) {
            $integration->audit('integration.abilities_changed', [
                'old' => ['abilities' => $old],
                'attributes' => ['abilities' => $integration->abilities],
            ]);
            $this->notice = 'Permisos actualizados.';
        }
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

        return view('livewire.admin.integration-manager', [
            'integrations' => $integrations,
            'selected' => $selected,
            'latestChanges' => $selected === null ? new Collection : $selected->statusEvents()
                ->with('app:id,slug')
                ->latest('id')
                ->limit(self::LATEST_CHANGES)
                ->get(),
            'tokenOwner' => $this->plainToken === null ? null : $selected,
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

        if ($recent->isFresh()) {
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
