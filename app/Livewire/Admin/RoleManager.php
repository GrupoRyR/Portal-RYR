<?php

namespace App\Livewire\Admin;

use App\Admin\RoleMatrix;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\Like;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Roles administration (brief §5.2, docs/diseno/AdminRoles.dc.html): the
 * permission matrix (App\Admin\RoleMatrix), the members of each role and
 * the emergency account status. Only `announcements_editor` is assigned
 * here; every action is re-authorized against App\Policies\RolePolicy.
 */
class RoleManager extends Component
{
    use AuthorizesRequests;

    /** Activity log name for role assignments. */
    public const AUDIT_LOG = 'roles';

    public const MIN_SEARCH = 2;

    public const MAX_RESULTS = 8;

    public string $search = '';

    #[Locked]
    public bool $adding = false;

    /** Confirmation of the last change, read by screen readers. */
    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Role::class);
    }

    public function startAdding(): void
    {
        $this->authorize('viewAny', Role::class);

        $this->adding = true;
        $this->search = '';
    }

    public function stopAdding(): void
    {
        $this->adding = false;
        $this->search = '';
    }

    /**
     * People who could become editors: name or email contains the search,
     * never emergency accounts nor current editors.
     *
     * @return Collection<int, User>
     */
    public function results(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < self::MIN_SEARCH) {
            return collect();
        }

        $like = Like::contains($term);

        return User::query()
            ->where('is_emergency', false)
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', RolePolicy::EDITOR))
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(name) like ? '.Like::ESCAPE, [$like])
                ->orWhereRaw('lower(email) like ? '.Like::ESCAPE, [$like]))
            ->with('company:id,name,short_name')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'email', 'company_id']);
    }

    public function addEditor(int $userId): void
    {
        $target = User::query()->findOrFail($userId);
        $this->authorize('assignEditor', [Role::class, $target]);

        if (! $target->hasRole(RolePolicy::EDITOR)) {
            $target->assignRole(RolePolicy::EDITOR);
            $this->audit('role.assigned', $target);
        }

        $this->stopAdding();
        $this->notice = "{$target->name} ahora es editor de anuncios.";
    }

    public function removeEditor(int $userId): void
    {
        $target = User::query()->findOrFail($userId);
        $this->authorize('removeEditor', [Role::class, $target]);

        if ($target->hasRole(RolePolicy::EDITOR)) {
            $target->removeRole(RolePolicy::EDITOR);
            $this->audit('role.removed', $target);
            $this->notice = "{$target->name} ya no es editor de anuncios.";
        }
    }

    public function render(): View
    {
        return view('livewire.admin.role-manager', [
            'roles' => RoleMatrix::ROLES,
            'sections' => RoleMatrix::sections(),
            'superAdmins' => $this->members('super_admin'),
            'portalAdmins' => $this->members('portal_admin'),
            'editors' => $this->members(RolePolicy::EDITOR),
            'emergency' => User::query()->where('is_emergency', true)->orderBy('id')->first(['id', 'email', 'two_factor_confirmed_at', 'last_login_at']),
            'results' => $this->results(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function members(string $role): Collection
    {
        return User::role($role)
            ->with('company:id,name,short_name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'company_id']);
    }

    private function audit(string $event, User $target): void
    {
        activity(self::AUDIT_LOG)
            ->performedOn($target)
            ->causedBy(Auth::user())
            ->event($event)
            ->withProperties(['role' => RolePolicy::EDITOR])
            ->log($event);
    }
}
