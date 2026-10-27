<?php

namespace App\View\Components\Layouts;

use App\Models\Company;
use App\Models\User;
use App\Policies\AdminSections;
use App\Policies\AnnouncementPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * Administration shell: sidebar with grouped navigation, user card and the
 * page content on the neutral ground (docs/diseno/AdminCompanies.dc.html).
 */
class Admin extends Component
{
    /**
     * Role labels, highest privilege first.
     */
    private const ROLE_LABELS = [
        'super_admin' => 'Súper administrador',
        'portal_admin' => 'Administrador del portal',
        'announcements_editor' => 'Editor de anuncios',
    ];

    public readonly ?User $user;

    public readonly ?Company $company;

    public function __construct(public ?string $title = null)
    {
        $user = Auth::user();

        $this->user = $user instanceof User ? $user : null;
        $this->company = $this->user?->company;
    }

    /**
     * Navigation groups. Items without a route are sections that do not exist
     * yet: they render as disabled text, never as links.
     *
     * @return list<array{label: ?string, items: list<array{label: string, icon: string, route: ?string, active: ?string}>}>
     */
    public function navigation(): array
    {
        $announcements = ['label' => 'Anuncios', 'icon' => 'megaphone', 'route' => 'admin.announcements.index', 'active' => 'admin.announcements.*'];

        // Announcements editors reach nothing else: the other sections are
        // left out, not merely disabled.
        if ($this->user === null || ! AnnouncementPolicy::manages($this->user)) {
            return [['label' => null, 'items' => [$announcements]]];
        }

        return array_values(array_filter([
            ['label' => 'Catálogo', 'items' => [
                ['label' => 'Aplicaciones', 'icon' => 'apps', 'route' => 'admin.apps.index', 'active' => 'admin.apps.*'],
                ['label' => 'Categorías', 'icon' => 'tag', 'route' => 'admin.categories.index', 'active' => 'admin.categories.*'],
                $announcements,
            ]],
            ['label' => 'Organización', 'items' => array_values(array_filter([
                // Only super admins manage companies (CompanyPolicy): nobody
                // else is offered a link that ends in a 403.
                $this->user?->can('viewAny', Company::class)
                    ? ['label' => 'Empresas', 'icon' => 'building', 'route' => 'admin.companies.index', 'active' => 'admin.companies.*']
                    : null,
                ['label' => 'Roles', 'icon' => 'user-check', 'route' => 'admin.roles.index', 'active' => 'admin.roles.*'],
                // API tokens: super admins only (AdminSections::INTEGRATIONS).
                $this->user->can(AdminSections::INTEGRATIONS)
                    ? ['label' => 'Integraciones', 'icon' => 'plug', 'route' => 'admin.integrations.index', 'active' => 'admin.integrations.*']
                    : null,
            ]))],
            ['label' => 'Seguimiento', 'items' => [
                ['label' => 'Auditoría', 'icon' => 'list', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*'],
                ['label' => 'Informe de uso', 'icon' => 'chart', 'route' => 'admin.usage.index', 'active' => 'admin.usage.*'],
            ]],
            // Configuración is for super admins only (AdminSections::SETTINGS).
            $this->user->can(AdminSections::SETTINGS)
                ? ['label' => null, 'items' => [
                    ['label' => 'Configuración', 'icon' => 'gear', 'route' => 'admin.settings.index', 'active' => 'admin.settings.*'],
                ]]
                : null,
        ]));
    }

    public function userInitials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->user?->name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return Str::upper(Str::substr($words[0], 0, 1).(count($words) > 1 ? Str::substr(end($words), 0, 1) : ''));
    }

    public function roleLabel(): ?string
    {
        foreach (self::ROLE_LABELS as $role => $label) {
            if ($this->user?->hasRole($role)) {
                return $label;
            }
        }

        return null;
    }

    public function render(): View
    {
        return view('components.layouts.admin');
    }
}
