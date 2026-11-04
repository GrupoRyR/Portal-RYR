<?php

namespace App\Livewire\Admin;

use App\Assistant\AssistantSettings;
use App\Assistant\AssistantSwitch;
use App\Assistant\Budget\AssistantBudget;
use App\Assistant\Model\ModelRef;
use App\Assistant\Model\ProviderRegistry;
use App\Models\User;
use App\Policies\AdminSections;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Asistente del portal» in Configuración (odd/tasks/asistente.md): the
 * runtime switch of /asistente (App\Assistant\AssistantSwitch, under the
 * deploy flag PORTAL_ASSISTANT_ENABLED), with warnings while the flag is
 * off or the API key is missing. Super admins only
 * (AdminSections::SETTINGS), re-authorized on every request. Acts at once,
 * apart from «Guardar cambios» of the settings form, like the Claude
 * connector (App\Livewire\Admin\McpConnector).
 */
class AssistantToggle extends Component
{
    use AuthorizesRequests;

    /** The Admin toggle; changed only through toggle(). */
    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public string $notice = '';

    public function mount(AssistantSwitch $switch): void
    {
        $this->authorize(AdminSections::SETTINGS);

        $this->enabled = $switch->turnedOn();
    }

    /**
     * Sets the opposite of what this page showed (the locked $enabled),
     * never the opposite of the stored value, so a stale page never flips
     * it back.
     */
    public function toggle(AssistantSwitch $switch): void
    {
        $this->authorize(AdminSections::SETTINGS);

        /** @var User $user */
        $user = Auth::user();
        $wanted = ! $this->enabled;
        $changed = $switch->turn($wanted, $user);

        $this->enabled = $switch->turnedOn();
        $this->notice = match (true) {
            $changed => $wanted ? 'Asistente encendido.' : 'Asistente apagado.',
            $wanted => 'Otra persona ya lo había encendido. El asistente sigue encendido.',
            default => 'Otra persona ya lo había apagado. El asistente sigue apagado.',
        };
    }

    public function render(AssistantSwitch $switch, AssistantSettings $settings, AssistantBudget $budget): View
    {
        $this->authorize(AdminSections::SETTINGS);
        $spent = $budget->spentThisMonth();
        $registry = app(ProviderRegistry::class);
        $providers = collect($settings->roles())->map(fn (ModelRef $ref) => $ref->provider)->unique();

        return view('livewire.admin.assistant-toggle', [
            'deployed' => $switch->deployed(),
            'missingKeys' => $providers->reject(fn (string $provider) => $registry->configured($provider))->map(fn (string $provider) => ProviderRegistry::keyName($provider))->unique()->values()->all(),
            'outsideAnthropic' => $providers->contains(fn (string $provider) => ProviderRegistry::outsideAnthropic($provider)),
            'settings' => $settings,
            'spent' => $spent,
            'budget' => $budget->limit(),
            'percent' => $budget->percent($spent),
        ]);
    }
}
