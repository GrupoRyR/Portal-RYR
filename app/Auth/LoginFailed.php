<?php

namespace App\Auth;

use RuntimeException;

/**
 * A Microsoft sign-in that must not proceed. The message is shown to the
 * user as is, so it is always Spanish and never carries technical details;
 * the reason code only goes to the security log (App\Audit\SecurityLog).
 */
class LoginFailed extends RuntimeException
{
    public const TENANT_NOT_ALLOWED = 'Tu organización no tiene acceso a este portal.';

    public const RETRY = 'No pudimos completar el inicio de sesión. Inténtalo de nuevo.';

    public function __construct(string $message, public readonly string $reason = 'retry')
    {
        parent::__construct($message);
    }

    public static function tenantNotAllowed(string $reason = 'tenant_not_allowed'): self
    {
        return new self(self::TENANT_NOT_ALLOWED, $reason);
    }

    public static function retry(string $reason = 'retry'): self
    {
        return new self(self::RETRY, $reason);
    }
}
