<?php

namespace App\Auth;

use RuntimeException;

/**
 * A Microsoft sign-in that must not proceed. The message is shown to the
 * user as is, so it is always Spanish and never carries technical details.
 */
class LoginFailed extends RuntimeException
{
    public const TENANT_NOT_ALLOWED = 'Tu organización no tiene acceso a este portal.';

    public const RETRY = 'No pudimos completar el inicio de sesión. Inténtalo de nuevo.';

    public static function tenantNotAllowed(): self
    {
        return new self(self::TENANT_NOT_ALLOWED);
    }

    public static function retry(): self
    {
        return new self(self::RETRY);
    }
}
