<?php

namespace App\Auth;

use App\Audit\SecurityLog;
use App\Microsoft\GraphClient;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\TenantDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Turns a Microsoft identity into a portal user: gate the tenant, pick the
 * signing tenant's company, find or create the user by (tenant, object ID)
 * only, refresh the profile and groups from Graph, and align the
 * managed roles. A successful sign-in is recorded as a security event
 * (App\Audit\SecurityLog) with the role changes it applied.
 *
 * Guests are never linked to their home account: per Microsoft's guidance
 * (id-token-claims-reference) a guest sign-in is a separate user whose
 * privileges come only from the tenant it signed in to. The email is only
 * data, never an identity.
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
     * A sign-in: {@see admit()} plus its record (last sign-in time and the
     * `login.microsoft` security event).
     *
     * @throws LoginFailed
     */
    public function handle(MicrosoftIdentity $identity, ?string $userAgent = null): User
    {
        ['user' => $user, 'roleChanges' => $roleChanges] = $this->admit($identity);

        $user->forceFill(['last_login_at' => now()])->save();

        SecurityLog::microsoftSignIn($user, $userAgent, $roleChanges);

        return $user;
    }

    /**
     * Everything a sign-in checks and refreshes, without recording one: for
     * flows that prove a Microsoft account but do not sign it in (linking).
     *
     * @return array{user: User, roleChanges: array{added: list<string>, removed: list<string>}}
     *
     * @throws LoginFailed
     */
    public function admit(MicrosoftIdentity $identity): array
    {
        $company = $this->companyFor($identity);

        $user = DB::transaction(fn () => $this->upsertUser($identity, $company));

        $this->syncFromGraph($user, $identity->accessToken);

        return ['user' => $user, 'roleChanges' => $this->syncRoles($user, $this->ownsItsEmail($identity))];
    }

    /**
     * The account {@see admit()} would leave for this identity, without
     * writing anything: the stored one, or a new one, with the company,
     * email and guest flag admit() would give it. Runs every check admit()
     * runs, so a flow can refuse before admit() writes. Never save it.
     *
     * @throws LoginFailed
     */
    public function preview(MicrosoftIdentity $identity): User
    {
        $company = $this->companyFor($identity);
        $user = $this->findOrNew($identity);

        $this->applyIdentity($user, $identity, $company);

        return $user->setRelation('company', $company);
    }

    /**
     * Gate the tenant and pick the signing tenant's company.
     *
     * @throws LoginFailed
     */
    private function companyFor(MicrosoftIdentity $identity): Company
    {
        if (! $this->tenants->isAllowed($identity->tenantId)) {
            throw LoginFailed::tenantNotAllowed();
        }

        if ($identity->email === null) {
            throw LoginFailed::retry('missing_email');
        }

        $company = $this->tenants->resolveCompany($identity->tenantId, $identity->email);

        if ($company === null) {
            throw LoginFailed::tenantNotAllowed('no_company');
        }

        return $company;
    }

    private function upsertUser(MicrosoftIdentity $identity, Company $company): User
    {
        $user = $this->findOrNew($identity);

        $this->applyIdentity($user, $identity, $company);
        $user->save();

        return $user;
    }

    /**
     * @throws LoginFailed
     */
    private function findOrNew(MicrosoftIdentity $identity): User
    {
        $user = User::query()
            ->where('tenant_id', $identity->tenantId)
            ->where('azure_oid', $identity->objectId)
            ->first()
            ?? new User([
                'name' => $identity->name,
                'tenant_id' => $identity->tenantId,
                'azure_oid' => $identity->objectId,
            ]);

        // Emergency accounts are local only; they never sign in through
        // Microsoft, even if an identity was somehow attached to them.
        if ($user->is_emergency) {
            throw LoginFailed::retry('emergency_account');
        }

        return $user;
    }

    private function applyIdentity(User $user, MicrosoftIdentity $identity, Company $company): void
    {
        $user->email = $identity->email;
        $user->microsoft_login_hint = $identity->loginHint;
        $user->is_guest = ! $identity->isHomeSignIn();
        $user->company_id = $company->id;
    }

    /**
     * Whether the email can be trusted for privileges. `mail` and the UPN
     * are set by the signing tenant's admins, so only a home sign-in (the
     * `idp` home tenant is the signing tenant) in the tenant whose company
     * owns the email domain vouches for it.
     */
    private function ownsItsEmail(MicrosoftIdentity $identity): bool
    {
        if (! $identity->isHomeSignIn()) {
            return false;
        }

        $owner = Company::query()
            ->get()
            ->first(fn (Company $company) => $company->ownsEmailDomain($identity->email));

        return $owner !== null && $owner->tenant_id === $identity->tenantId;
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

        // The profile photo is not read: no page shows it, and on the public
        // disk anyone could fetch it at a guessable address. Photos stored
        // by earlier versions are removed by `portal:purge-public-avatars`.

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
     *
     * `portal_admin` follows membership of the pinned group of the trusted
     * tenant (App\Auth\PortalAdminGroup), never a group name.
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    private function syncRoles(User $user, bool $verifiedOwnEmail): array
    {
        $changes = ['added' => [], 'removed' => []];
        $listed = in_array($user->email, config('portal.super_admin_emails', []), true);

        if (! $listed) {
            $this->toggleRole($user, self::SUPER_ADMIN, false, $changes);
        } elseif ($verifiedOwnEmail) {
            $this->toggleRole($user, self::SUPER_ADMIN, true, $changes);
        }

        $this->toggleRole(
            $user,
            self::PORTAL_ADMIN,
            PortalAdminGroup::contains($user),
            $changes,
        );

        return $changes;
    }

    /**
     * @param  array{added: list<string>, removed: list<string>}  $changes
     */
    private function toggleRole(User $user, string $role, bool $shouldHave, array &$changes): void
    {
        if ($shouldHave && ! $user->hasRole($role)) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
            $changes['added'][] = $role;
        } elseif (! $shouldHave && $user->hasRole($role)) {
            $user->removeRole($role);
            $changes['removed'][] = $role;
        }
    }
}
