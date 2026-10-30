<?php

namespace App\Livewire\Admin;

use App\Mcp\Connections;
use App\Mcp\McpSwitch;
use App\Models\McpConnection;
use App\Models\User;
use App\Policies\AdminSections;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Conector de Claude» in Configuración (odd/tasks/conector-mcp.md): the
 * runtime switch of the whole connector (App\Mcp\McpSwitch, under the
 * deploy flag PORTAL_MCP_ENABLED) and the active connections, newest
 * first and capped at {@see LIMIT}, each with «Revocar». Super admins only
 * (AdminSections::SETTINGS); every action is re-authorized. Both actions
 * act at once, apart from «Guardar cambios» of the settings form.
 */
class McpConnector extends Component
{
    use AuthorizesRequests;

    public const LIMIT = 50;

    /** The Admin toggle; changed only through toggle(). */
    #[Locked]
    public bool $enabled = true;

    #[Locked]
    public string $notice = '';

    public function mount(McpSwitch $switch): void
    {
        $this->authorize(AdminSections::SETTINGS);

        $this->enabled = $switch->turnedOn();
    }

    public function toggle(McpSwitch $switch): void
    {
        $this->authorize(AdminSections::SETTINGS);

        /** @var User $user */
        $user = Auth::user();
        $switch->turn(! $switch->turnedOn(), $user);

        $this->enabled = $switch->turnedOn();
        $this->notice = $this->enabled ? 'Conector encendido.' : 'Conector apagado.';
    }

    public function revoke(int $id, Connections $connections): void
    {
        $this->authorize(AdminSections::SETTINGS);

        /** @var User $user */
        $user = Auth::user();
        $connection = McpConnection::query()->active()->with(['client', 'user'])->find($id);

        $this->notice = $connection !== null && $connections->revokeByAdmin($connection, $user)
            ? 'Se revocó la conexión de Claude («'.$connection->client?->name.'») de '.$connection->user?->name.'.'
            : 'Esa conexión ya no estaba activa.';
    }

    public function render(McpSwitch $switch): View
    {
        $active = McpConnection::query()->active();

        return view('livewire.admin.mcp-connector', [
            'deployed' => $switch->deployed(),
            'total' => (clone $active)->count(),
            'connections' => $active
                ->with(['client', 'user.company', 'accounts.user.company'])
                ->latest('id')
                ->limit(self::LIMIT)
                ->get(),
        ]);
    }
}
