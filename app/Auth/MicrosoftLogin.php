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

        [$company, $isGuest, $emailTrusted] = $this->resolveCompany($identity);

        $user = DB::transaction(fn () => $this->upsertUser($identity, $company, $isGuest, $emailTrusted));

        $this->syncFromGraph($user, $identity->accessToken);
        $this->syncRoles($user, verifiedOwnEmail: $emailTrusted && ! $isGuest);

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /**
     * Decide the company and whether the email may be trusted. `mail` and
     * the UPN are set by the signing tenant's admins, so an email is only
     * trusted when its domain belongs to the company of the tenant that
     * vouches for the account:
     *
     * - home sign-in in the tenant whose company owns the domain → trusted;
     * - domain owned by a company of ANOTHER tenant → a guest (e.g. a
     *   Derivados user invited into Segurex), accepted only when the
     *   validated `idp` claim proves that tenant is the account's home;
     *   the owner must be active;
     * - otherwise the company of the token's tenant, email untrusted.
     *
     * @return array{0: Company, 1: bool, 2: bool} company, is guest, email trusted
     */
    private function resolveCompany(MicrosoftIdentity $identity): array
    {
        $owner = Company::query()
            ->get()
            ->first(fn (Company $company) => $company->ownsEmailDomain($identity->email));

        if ($owner !== null && $owner->tenant_id !== $identity->tenantId) {
            if ($identity->homeTenantId !== $owner->tenant_id || ! $owner->is_active) {
                throw LoginFailed::tenantNotAllowed();
            }

            return [$owner, true, true];
        }

        $company = $this->tenants->resolveCompany($identity->tenantId, $identity->email);

        if ($company === null) {
            throw LoginFailed::tenantNotAllowed();
        }

        $emailTrusted = $owner !== null && $identity->isHomeSignIn();

        return [$company, false, $emailTrusted];
    }

    private function upsertUser(MicrosoftIdentity $identity, Company $company, bool $isGuest, bool $emailTrusted): User
    {
        $user = User::query()
            ->where('tenant_id', $identity->tenantId)
            ->where('azure_oid', $identity->objectId)
            ->first();

        if ($user === null && $emailTrusted) {
            $user = User::query()->whereRaw('lower(email) = ?', [$identity->email])->first();
        }

        if ($user === null) {
            // An untrusted email must never reach someone else's account.
            if ($this->emailTakenByAnotherUser(new User, $identity->email)) {
                throw LoginFailed::retry();
            }

            $user = new User(['name' => $identity->name]);
        }

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
     *
     * `super_admin` is granted only when the listed email was vouched for by
     * the tenant owning its domain (never on a guest sign-in) and removed
     * once the email leaves the list; otherwise it is left as it was.
     */
    private function syncRoles(User $user, bool $verifiedOwnEmail): void
    {
        $listed = in_array($user->email, config('portal.super_admin_emails', []), true);

        if (! $listed) {
            $this->toggleRole($user, self::SUPER_ADMIN, false);
        } elseif ($verifiedOwnEmail) {
            $this->toggleRole($user, self::SUPER_ADMIN, true);
        }

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
