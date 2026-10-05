<?php

namespace App\Auth;

use App\Microsoft\GraphClient;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Turns a Microsoft identity into a portal user: gate the tenant, pick the
 * company, find or create the user (linking guests by email), refresh the
 * profile, photo and groups from Graph, and align the managed roles.
 */
class MicrosoftLogin
{
    public const SUPER_ADMIN = 'super_admin';

    public const PORTAL_ADMIN = 'portal_admin';

    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly GraphClient $graph,
    ) {}

    /**
     * @throws LoginFailed
     */
    public function handle(MicrosoftIdentity $identity): User
    {
        if (! $this->tenants->isAllowed($identity->tenantId)) {
            throw LoginFailed::tenantNotAllowed();
        }

        if ($identity->email === null) {
            throw LoginFailed::retry();
        }

        [$company, $isGuest] = $this->resolveCompany($identity);

        $user = DB::transaction(fn () => $this->upsertUser($identity, $company, $isGuest));

        $this->syncFromGraph($user, $identity->accessToken);
        $this->syncRoles($user);

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /**
     * A user whose email domain belongs to a company of ANOTHER tenant is a
     * guest (e.g. a Derivados user invited into Segurex): their company is
     * the domain owner, which must be active. Everyone else gets the
     * company of the token's tenant.
     *
     * @return array{0: Company, 1: bool}
     */
    private function resolveCompany(MicrosoftIdentity $identity): array
    {
        $owner = Company::query()
            ->where('tenant_id', '!=', $identity->tenantId)
            ->get()
            ->first(fn (Company $company) => $company->ownsEmailDomain($identity->email));

        if ($owner !== null) {
            if (! $owner->is_active) {
                throw LoginFailed::tenantNotAllowed();
            }

            return [$owner, true];
        }

        $company = $this->tenants->resolveCompany($identity->tenantId, $identity->email);

        if ($company === null) {
            throw LoginFailed::tenantNotAllowed();
        }

        return [$company, false];
    }

    private function upsertUser(MicrosoftIdentity $identity, Company $company, bool $isGuest): User
    {
        $user = User::query()
            ->where('tenant_id', $identity->tenantId)
            ->where('azure_oid', $identity->objectId)
            ->first()
            ?? User::query()->whereRaw('lower(email) = ?', [$identity->email])->first()
            ?? new User(['name' => $identity->name]);

        // A guest sign-in never replaces the home-tenant identity already
        // stored for the user; a home sign-in always records it.
        if (! $isGuest || $user->azure_oid === null) {
            $user->tenant_id = $identity->tenantId;
            $user->azure_oid = $identity->objectId;
        }

        if ($user->email !== $identity->email && ! $this->emailTakenByAnotherUser($user, $identity->email)) {
            $user->email = $identity->email;
        }

        $user->company_id = $company->id;
        $user->save();

        return $user;
    }

    private function emailTakenByAnotherUser(User $user, string $email): bool
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();
    }

    /**
     * Each Graph read is independent: a failure is logged and the stored
     * value is kept, so Graph problems never block a sign-in.
     */
    private function syncFromGraph(User $user, string $accessToken): void
    {
        $this->attempt($user, 'profile', function () use ($user, $accessToken) {
            $profile = $this->graph->profile($accessToken);

            if (filled($profile['display_name'])) {
                $user->name = $profile['display_name'];
            }

            $user->job_title = $profile['job_title'];
            $user->save();
        });

        $this->attempt($user, 'photo', function () use ($user, $accessToken) {
            $photo = $this->graph->photo($accessToken);

            if ($photo === null) {
                return;
            }

            $path = "avatars/{$user->id}.jpg";
            Storage::disk('public')->put($path, $photo);
            $user->forceFill(['photo_path' => $path])->save();
        });

        $this->attempt($user, 'groups', function () use ($user, $accessToken) {
            $user->syncGroups($this->graph->groups($accessToken));
        });
    }

    private function attempt(User $user, string $what, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning("Microsoft Graph {$what} sync failed at sign-in; keeping stored data.", [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Only the roles derived from Microsoft are touched; any other role
     * (e.g. `announcements_editor`) is managed inside the portal.
     */
    private function syncRoles(User $user): void
    {
        $this->toggleRole(
            $user,
            self::SUPER_ADMIN,
            in_array($user->email, config('portal.super_admin_emails', []), true),
        );

        $this->toggleRole(
            $user,
            self::PORTAL_ADMIN,
            $user->isInGroup((string) config('portal.admin_group')),
        );
    }

    private function toggleRole(User $user, string $role, bool $shouldHave): void
    {
        if ($shouldHave && ! $user->hasRole($role)) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        } elseif (! $shouldHave && $user->hasRole($role)) {
            $user->removeRole($role);
        }
    }
}
