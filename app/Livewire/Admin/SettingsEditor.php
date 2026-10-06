<?php

namespace App\Livewire\Admin;

use App\Backups\BackupOverview;
use App\Backups\BackupRequests;
use App\Catalog\AppUrlPolicy;
use App\Models\App;
use App\Models\User;
use App\Policies\AdminSections;
use App\Settings\PortalSettings;
use App\Support\SupportLink;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Configuración (docs/diseno/AdminSettings.dc.html): portal-wide settings
 * stored by App\Settings\PortalSettings. Super admins only; every action
 * is re-authorized. Domains are added and removed in the form and only
 * applied, with everything else, by «Guardar cambios». The «Respaldo
 * diario» card is separate: «Respaldar ahora» acts at once.
 */
class SettingsEditor extends Component
{
    use AuthorizesRequests;

    public const MAX_HOSTS = 50;

    public string $supportUrl = '';

    public int $idleTimeout = 0;

    public bool $microsoftLogout = false;

    /** @var list<string> Changed only through addHost() and removeHost(). */
    #[Locked]
    public array $hosts = [];

    public string $newHost = '';

    public int $apiStatusTtl = 0;

    /** Idle timeout in force when the form was loaded: always a valid choice. */
    #[Locked]
    public int $currentIdleTimeout = 0;

    #[Locked]
    public string $notice = '';

    public function mount(PortalSettings $settings): void
    {
        $this->authorize(AdminSections::SETTINGS);

        $this->fill([
            'supportUrl' => (string) $settings->supportUrl(),
            'idleTimeout' => $settings->idleTimeoutMinutes(),
            'currentIdleTimeout' => $settings->idleTimeoutMinutes(),
            'microsoftLogout' => $settings->signsOutOfMicrosoft(),
            'hosts' => $settings->allowedAppHosts(),
            'apiStatusTtl' => $settings->apiStatusTtlHours(),
        ]);
    }

    public function addHost(): void
    {
        $this->authorize(AdminSections::SETTINGS);
        $this->resetErrorBag();

        $host = strtolower(trim($this->newHost));

        $error = match (true) {
            ! AppUrlPolicy::isValidPattern($host) => 'Escribe solo el dominio, como segurex.com.co o sgx-*.vercel.app (el * va junto a un prefijo).',
            in_array($host, $this->hosts, true) => 'Ese dominio ya está en la lista.',
            count($this->hosts) >= self::MAX_HOSTS => 'La lista admite hasta '.self::MAX_HOSTS.' dominios.',
            default => null,
        };

        if ($error !== null) {
            $this->addError('newHost', $error);

            return;
        }

        $this->hosts[] = $host;
        $this->newHost = '';
        $this->notice = '';
    }

    public function removeHost(string $host): void
    {
        $this->authorize(AdminSections::SETTINGS);
        $this->resetErrorBag();

        if (! in_array($host, $this->hosts, true)) {
            return;
        }

        $remaining = array_values(array_diff($this->hosts, [$host]));

        if ($error = $this->hostsError($remaining, $host)) {
            $this->addError('hosts', $error);

            return;
        }

        $this->hosts = $remaining;
        $this->notice = '';
    }

    public function save(PortalSettings $settings): void
    {
        $this->authorize(AdminSections::SETTINGS);
        $this->resetErrorBag();
        $this->notice = '';
        $this->supportUrl = trim($this->supportUrl);

        $this->validate([
            'supportUrl' => ['nullable', 'string', 'max:'.PortalSettings::SUPPORT_URL_MAX, 'url:https', $this->supportUrlRule()],
            'idleTimeout' => ['required', 'integer', Rule::in($this->idleTimeoutChoices())],
            'microsoftLogout' => ['boolean'],
            'apiStatusTtl' => ['required', 'integer', Rule::in(PortalSettings::API_STATUS_TTL_CHOICES)],
        ], [
            'supportUrl.max' => 'La dirección admite hasta :max caracteres.',
            'supportUrl.url' => 'Escribe una dirección completa que empiece por https://.',
            'idleTimeout.in' => 'Elige una de las opciones.',
            'apiStatusTtl.in' => 'Elige una de las opciones.',
        ]);

        // The list was checked when each domain was removed; an app may
        // have been published on a removed domain since then.
        if ($error = $this->hostsError($this->hosts)) {
            $this->addError('hosts', $error);

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $settings->update([
            PortalSettings::SUPPORT_URL => $this->supportUrl,
            PortalSettings::IDLE_TIMEOUT => $this->idleTimeout,
            PortalSettings::MICROSOFT_LOGOUT => $this->microsoftLogout,
            PortalSettings::ALLOWED_APP_HOSTS => $this->hosts,
            PortalSettings::API_STATUS_TTL => $this->apiStatusTtl,
        ], $user);

        $this->currentIdleTimeout = $settings->idleTimeoutMinutes();
        $this->hosts = $settings->allowedAppHosts();
        $this->notice = 'Cambios guardados.';
    }

    /**
     * «Respaldar ahora»: leaves a request that the scheduler runs the next
     * minute; the dump never runs inside this request.
     */
    public function requestBackup(BackupRequests $requests): void
    {
        $this->authorize(AdminSections::SETTINGS);
        $this->resetErrorBag('backup');

        if ($refusal = $requests->refusal()) {
            $this->addError('backup', $refusal);

            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $requests->request($user);
    }

    public function render(): View
    {
        return view('livewire.admin.settings-editor', [
            'idleOptions' => $this->idleOptions(),
            'ticketExample' => $this->ticketExample(),
            'backup' => BackupOverview::current(),
        ]);
    }

    /**
     * Why the list cannot be saved as is, or null: it may not be empty and
     * every app that is not hidden must still be allowed by it.
     *
     * @param  list<string>  $hosts
     */
    private function hostsError(array $hosts, ?string $removed = null): ?string
    {
        if ($hosts === []) {
            return 'Debe quedar al menos un dominio.';
        }

        foreach ($hosts as $host) {
            if (! AppUrlPolicy::isValidPattern($host)) {
                return "«{$host}» no es un dominio válido.";
            }
        }

        $names = $this->appsOutside($hosts)->pluck('name');

        if ($names->isEmpty()) {
            return null;
        }

        $subject = $removed === null ? 'Faltan dominios que' : "No se puede quitar «{$removed}»: lo";

        return "{$subject} usan estas apps: {$names->implode(', ')}. Cambia primero su dirección u ocúltalas.";
    }

    /**
     * Web apps that are not hidden and whose URL the hosts would not allow.
     *
     * @param  list<string>  $hosts
     * @return Collection<int, App>
     */
    private function appsOutside(array $hosts): Collection
    {
        $policy = new AppUrlPolicy($hosts);

        return App::query()
            ->where('type', App::TYPE_WEB)
            ->where('status', '!=', App::STATUS_HIDDEN)
            ->whereNotNull('url')
            ->orderBy('name')
            ->get(['id', 'name', 'url'])
            ->reject(fn (App $app) => $policy->allows($app->url))
            ->values();
    }

    private function supportUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $parts = is_string($value) ? parse_url($value) : false;

            if ($parts === false
                || ! str_starts_with(strtolower((string) $value), 'https://')
                || isset($parts['user'])
                || isset($parts['pass'])
                || preg_match('/\s/u', (string) $value) === 1) {
                $fail('Escribe una dirección completa que empiece por https://, sin usuario ni contraseña.');
            }
        };
    }

    /**
     * @return list<int>
     */
    private function idleTimeoutChoices(): array
    {
        return array_values(array_unique([...PortalSettings::IDLE_TIMEOUT_CHOICES, $this->currentIdleTimeout]));
    }

    /**
     * Choices of the idle timeout select, labelled. The timeout in force is
     * offered too when it is not a standard choice (the server lifetime).
     *
     * @return array<int, string>
     */
    private function idleOptions(): array
    {
        $options = [];
        foreach (PortalSettings::IDLE_TIMEOUT_CHOICES as $minutes) {
            $options[$minutes] = self::duration($minutes);
        }

        if (! isset($options[$this->currentIdleTimeout])) {
            $options = [$this->currentIdleTimeout => self::duration($this->currentIdleTimeout).' (valor actual del servidor)'] + $options;
        }

        return $options;
    }

    private static function duration(int $minutes): string
    {
        return match (true) {
            $minutes % 60 === 0 && $minutes === 60 => '1 hora',
            $minutes % 60 === 0 => ($minutes / 60).' horas',
            default => "{$minutes} minutos",
        };
    }

    /**
     * «Así llega el ticket»: the address the button opens for this admin,
     * with an app of the catalog as example.
     */
    private function ticketExample(): string
    {
        $url = trim($this->supportUrl);
        $base = str_starts_with(strtolower($url), 'https://') && mb_strlen($url) <= PortalSettings::SUPPORT_URL_MAX && preg_match('/\s/u', $url) !== 1
            ? $url
            : '[URL de tickets]';

        /** @var User|null $user */
        $user = Auth::user();
        $app = App::query()->where('status', '!=', App::STATUS_HIDDEN)->orderBy('sort_order')->orderBy('name')->value('slug');

        return SupportLink::build($base, $user?->company?->slug ?? 'empresa', $app ?? 'cartera');
    }
}
