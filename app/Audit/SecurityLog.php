<?php

namespace App\Audit;

use App\Models\User;
use App\Support\DeviceClassifier;

/**
 * Sign-ins and re-authentications in the activity log (log name
 * {@see LOG}), shown in Auditoría as «Seguridad». Never a secret: no
 * password, code, token, IP address or User-Agent — only the device type.
 * The Laravel log lines written by the sign-in classes stay as they are.
 */
final class SecurityLog
{
    public const LOG = 'security';

    public const MICROSOFT = 'login.microsoft';

    public const MICROSOFT_REJECTED = 'login.microsoft_rejected';

    public const EMERGENCY = 'login.emergency';

    public const EMERGENCY_FAILED = 'login.emergency_failed';

    public const REAUTH_CONFIRMED = 'reauth.confirmed';

    public const REAUTH_FAILED = 'reauth.failed';

    public const ROLES_SYNCED = 'roles.synced';

    /**
     * @param  array{added: list<string>, removed: list<string>}  $roleChanges  Roles synced at this sign-in.
     */
    public static function microsoftSignIn(User $user, ?string $userAgent, array $roleChanges): void
    {
        self::write(self::MICROSOFT, $user, [
            'company_id' => $user->company?->id,
            'company' => $user->company?->name,
            'device' => DeviceClassifier::classify($userAgent),
            'roles_added' => $roleChanges['added'],
            'roles_removed' => $roleChanges['removed'],
        ]);
    }

    public static function microsoftRejected(string $reason, ?string $tenantId): void
    {
        self::write(self::MICROSOFT_REJECTED, null, [
            'reason' => $reason,
            'tenant_id' => $tenantId === null ? null : strtolower($tenantId),
        ]);
    }

    public static function emergencySignIn(User $user, ?string $userAgent): void
    {
        self::write(self::EMERGENCY, $user, [
            'email' => $user->email,
            'step' => 'code',
            'device' => DeviceClassifier::classify($userAgent),
        ]);
    }

    /**
     * @param  string  $step  `credentials` or `code`.
     * @param  string  $reason  `invalid`, `throttled` or `no_pending_step`.
     */
    public static function emergencyFailed(?string $email, string $step, string $reason): void
    {
        self::write(self::EMERGENCY_FAILED, null, [
            'email' => $email,
            'step' => $step,
            'reason' => $reason,
        ]);
    }

    public static function reauthentication(User $user, bool $confirmed, ?string $userAgent): void
    {
        self::write($confirmed ? self::REAUTH_CONFIRMED : self::REAUTH_FAILED, $user, [
            'device' => DeviceClassifier::classify($userAgent),
        ]);
    }

    /**
     * Roles the system changed outside a sign-in (`portal:sync-portal-admins`).
     * No causer: Auditoría shows «Sistema»; the user is the subject.
     *
     * @param  array{added: list<string>, removed: list<string>}  $roleChanges
     */
    public static function rolesSynced(User $user, array $roleChanges): void
    {
        self::write(self::ROLES_SYNCED, null, [
            'name' => $user->name,
            'email' => $user->email,
            'roles_added' => $roleChanges['added'],
            'roles_removed' => $roleChanges['removed'],
        ], $user);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function write(string $event, ?User $causer, array $properties, ?User $subject = null): void
    {
        $logger = activity(self::LOG)->event($event)->withProperties($properties);

        // Without a causer the entry stays anonymous even if someone else is
        // signed in on this session (e.g. a rejected attempt).
        $causer === null ? $logger->causedByAnonymous() : $logger->causedBy($causer)->performedOn($causer);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $logger->log($event);
    }
}
